#!/usr/bin/python3
"""Video-to-video transformation pipeline for Codename Mage.

The script orchestrates frame extraction, Stable Diffusion processing, progress
tracking, and output assembly. It intentionally keeps heavy operations minimal
by caching metadata lookups, reusing ControlNet units, and avoiding redundant
reads when probing input files.
"""

import argparse
import json
import math
import os
import random
import subprocess
import sys
import time
import warnings
from datetime import datetime

import ffmpeg
import imageio.v2 as imageio
import imageio.v3 as iio
import mysql.connector
import numpy as np
from apng import APNG
from moviepy.editor import AudioFileClip, VideoFileClip
from PIL import Image, ImageSequence
from progressbar import AdaptiveETA, Bar, FormatLabel, Percentage, ProgressBar

import webuiapi

warnings.filterwarnings("ignore")

# Constants
DB_CONFIG = {
    "user": "laravel",
    "password": "zxcvfdsA",
    "host": "webtop.local",
    "database": "mage",
}

options = {
    "preview_frame_count": 10,
    "preview_start_frame": 5,
    "workRootDir": "/opt/jobs",
    "api_host": "127.0.0.1",
    "api_port": "7860",
    "finalDir": "/opt/processed/"
}

WIDGETS = [
    FormatLabel(''),
    ' ',
    Percentage(),
    ' ',
    Bar(),
    AdaptiveETA()
]

starttime = time.time()
audioFile = None

class VideoProcessor:
    """Process videos frame-by-frame through Stable Diffusion."""

    def __init__(self, args):
        self.args = args
        self.frames = []
        self.animated_frames = []
        self.controlnetUnits = []
        self.isAnimated = None

        api_port = self.args.api_port or options.get("api_port")
        cli_hosts = (
            [h.strip() for h in self.args.api_hosts.split(",") if h.strip()]
            if getattr(self.args, "api_hosts", None)
            else None
        )
        api_host = self.args.api_host if getattr(self.args, "api_host", None) else None
        api_hosts = cli_hosts if cli_hosts else api_host or options.get("api_host")

        # create API client with custom host, port
        self.api = webuiapi.WebUIApi(
            host=api_hosts,
            port=api_port,
            sampler=self.args.sampler,
            steps=self.args.steps,
        )
        self.cnx = mysql.connector.connect(**DB_CONFIG)

    def __del__(self):
        self.cnx.close()

    def debugPrint(self, object):
        if self.args.debug == True:
            print(object)
    def logArgs(self, **args):
        if self.args.debug is True:
          self.debugPrint("Calling img2img with parameters:")
          self.debugPrint(args)
        return args

    def add_image_to_gif(self, image_file, output_path):
        new_frame = Image.open(image_file)
        self.frames.append(new_frame)
        self.frames[0].save(output_path, format='webp',
                append_images=self.frames[1:],
                save_all=True,
                duration=0.1, loop=0)

    def extractAudio(self, path):
        clip = VideoFileClip(path)
        if clip.audio is not None:
            audioBasename = os.path.basename(self.args.outfile)
            self.audioFile = "/opt/jobs/{0}/{1}".format(self.args.jobid, os.path.splitext(audioBasename)[0]+'.mp3')       
            clip.audio.write_audiofile(self.audioFile, verbose=False, logger=None)
        else:
            self.audioFile = None

    def attachAudio(self, path):
        if self.audioFile is None:
            print("No audio file present")
            return

        if os.path.isfile(path) is not True:
            print('Error,' + path +' not found. attachment dropped')
            exit(0)

        if os.path.isfile(self.audioFile) is not True:
            print("Error:" + self.audioFile + " not found, audiofile attachment dropped")
            exit(0)

        videoclip = VideoFileClip(path)
        print("\nMaking {0}/{1} \n".format(self.audioFile, path))
        audioclip = AudioFileClip(self.audioFile)
        new_audioclip = audioclip.subclip(0, videoclip.duration)  # Make the audio match the duration of the video
        new_videoclip = videoclip.set_audio(new_audioclip)

        new_videoclip.write_videofile(path+".tmp.mp4", verbose=False, logger=None)

        # Replace the original video file with the new one
        os.remove(path)
        os.remove(self.audioFile)
        os.rename(path+".tmp.mp4", path)
        self.debugPrint("Audio file attached to "+path)
        endtime = round(time.time() - starttime, 0)
        print("\nTotal time taken: {0} seconds".format(endtime))


    def make_ping_pong_gif(self, image_file, output_path):
        self.debugPrint("Appending "+image_file+" to "+output_path)
        
        image = imageio.imread(image_file)

        if image.ndim == 2:
            image = np.stack((image,) * 3, axis=-1)
        elif image.shape[2] == 4:
            image = image[:, :, :3]

        self.animated_frames.append(image_file)
        self.debugPrint("Frame amount {0} / {1}".format(len(self.animated_frames), int(self.args.limit_frames_amount)))

        if (len(self.animated_frames) == int(self.args.limit_frames_amount)):
            self.animated_frames += self.animated_frames[-2::-1]
            
        self.debugPrint("Writing {0} frames to {1}".format(len(self.animated_frames), output_path))
        APNG.from_files(self.animated_frames, delay=100).save(output_path)

#      
    def update_db(self, query, params):
        try:
            cursor = self.cnx.cursor()
            cursor.execute(query, params)
            self.cnx.commit()
            cursor.close()
        except mysql.connector.Error as err:
            print(f"Failed to update database: {err}")
            self.update_status('error')
            sys.exit(1)
    def get_status(self):
        if self.args.jobid is not None and self.args.jobid > 0:
            try:
                cursor = self.cnx.cursor()
                query = "SELECT status FROM video_jobs WHERE id = %s"
                cursor.execute(query, (self.args.jobid,))
                statusEntry = cursor.fetchone()

                cursor.close()
                if statusEntry is not None and statusEntry:
                    return statusEntry[0]
                else:
                    return True
            except mysql.connector.Error as err:
                print(f"Failed to get status: {err}")
                self.update_status('error')
                sys.exit(1)
            
    def update_status(self, status):
        if status == "finished":
            query = "UPDATE video_jobs SET status=%s, progress=100 WHERE id = %s"
        else:
            query = "UPDATE video_jobs SET status=%s WHERE id = %s"
        self.update_db(query, (status, self.args.jobid))

    def update_preview_img(self, url):
        endtime = round(time.time() - starttime, 0)
        query = "UPDATE video_jobs SET job_time = %s, preview_img = %s WHERE id = %s"
        self.update_db(query, (int(endtime), url, self.args.jobid))
    def update_preview_animation(self, url):
        endtime = round(time.time() - starttime, 0)
        query = "UPDATE video_jobs SET job_time = %s, preview_animation = %s WHERE id = %s"
        self.update_db(query, (int(endtime), url, self.args.jobid))

    def update_progress(self, progress, remaining):
        endtime = time.time() - starttime
        print("Updating time: {0} progress: {1} time_left: {2}".format(int(endtime), int(progress), int(remaining)))
        query = "UPDATE video_jobs SET job_time = %s, progress = %s, estimated_time_Left = %s WHERE id = %s"
        self.update_db(query, (int(endtime), int(progress), int(remaining), self.args.jobid))

    def limit(self, f):
        nr = int(f)
        if (nr > 100):
            return 100
        return f
    def makeControlnetUnit(self, params):
        unit_params = dict(item.split("=") for item in params.split(", "))
        unit_params['weight'] = float(unit_params['weight'])
        
        if 'loopback' in unit_params:
            unit_params['loopback'] = True
        else:
            unit_params['loopback'] = False

        unit = webuiapi.ControlNetUnit(**unit_params)
        self.debugPrint("Added Controlnet Unit with params: {0}".format(unit_params))
        self.controlnetUnits.append(unit)

    def controlnetLoopback(self, image):

        if len(self.controlnetUnits) > 0:
            for i, unit in enumerate(self.controlnetUnits):
                if unit is not None and unit.loopback is True:             
                    self.controlnetUnits[i].input_image = image;

    def initControlnetUnits(self):
        self.controlnetUnits = []
        if self.args.unit1_params is not None:
            self.makeControlnetUnit(self.args.unit1_params);
        if self.args.unit2_params is not None:
            self.makeControlnetUnit(self.args.unit2_params);
        if self.args.unit3_params is not None:
            self.makeControlnetUnit(self.args.unit3_params);
        self.debugPrint("{0} controlnet units found".format(len(self.controlnetUnits)))


    def _probe_metadata(self, path):
        """Read FPS and duration once, falling back to resized proxy if needed."""

        metadata = iio.immeta(path, plugin="pyav")
        fps = self.args.fps or metadata.get("fps")
        duration = (
            self.args.duration
            if self.args.duration and self.args.duration > 0
            else metadata.get("duration")
        )

        if fps is None or duration is None:
            newpath = os.path.splitext(path)[0] + "_temp.mp4"
            resize_script = "/opt/bin/resize_video.sh"
            resize_cmd = [resize_script, path, newpath]
            resize_cmd_output = subprocess.check_output(resize_cmd).decode("utf-8")
            self.debugPrint(resize_cmd_output.strip())
            metadata = iio.immeta(newpath, plugin="pyav")
            fps = self.args.fps or metadata.get("fps")
            duration = (
                self.args.duration
                if self.args.duration and self.args.duration > 0
                else metadata.get("duration")
            )
            path = newpath

        return fps, duration, path

    
    def isAnimatedGif(self):
        if (self.isAnimated is not None):
            return self.isAnimated
        if (self.isGif()):
            gif = Image.open(self.args.path)
            try:
                gif.seek(1)
            except EOFError:
                self.isAnimated = False
            else:
                self.isAnimated = True
            return self.isAnimated

    def isGif(self):
        return self.args.path.lower().endswith('.gif') or self.args.path.lower().endswith('.webp') or self.args.path.lower().endswith('.png') or  self.args.path.lower().endswith('.jpg')
      
    def processFrame(self, frame):
        
        pil_img = Image.fromarray(frame)
        w, h = pil_img.size
        self.debugPrint("Converting from {0}x{1} image to {2}x{3}".format(w,h,self.args.width,self.args.height))
        self.controlnetLoopback(pil_img)
        imgargs = self.logArgs(images=[pil_img],
                prompt=self.args.prompt,
                negative_prompt=self.args.negative_prompt,   
                denoising_strength=self.args.denoising_strength,
                sampler_index=self.args.sampler,
                seed=self.args.seed,
                steps=self.args.steps,
                cfg_scale=self.args.cfg_scale,
                width=self.args.width,
                height=self.args.height,
                tiling=self.args.tiling,
                restore_faces=self.args.restore_faces,
                controlnet_units=self.controlnetUnits)
        

        result = self.api.img2img(**imgargs);
        
        # If image_data is already a PIL Image object, you can convert it to a numpy array
        return np.array(result.image)
    
    def getFrames(self):
        if self.isGif() is True:
            gif = Image.open(self.args.path)
            if self.isAnimatedGif() is True:
                # Treat the GIF as a video file
                frames = [frame.copy() for frame in ImageSequence.Iterator(gif)]
            else:
                # Treat the GIF as a single-frame video
                frames = [gif]
        else:
            # The input is not a GIF, so treat it as a video file
            frames = iio.imiter(self.args.path, plugin="pyav")
        return frames
    
    def updateProgress(self, frameAmount, frame_start_time, N, pbar, processed_frames=0):
            
            if (processed_frames > 0):
                self.processed_frames+=1
                progressPercentage = math.floor((self.processed_frames / frameAmount)*100 )
            else:
                if (frameAmount == 1): 
                    progressPercentage = 50
                else:
                    progressPercentage = 100/frameAmount

            
            remaining_frames = frameAmount - self.processed_frames
            self.frame_times.append(time.time() - frame_start_time)
            # Calculate the average time per frame so far
            avg_time_per_frame = sum(self.frame_times) / len(self.frame_times)
            if (int(avg_time_per_frame) == 0):
                avg_time_per_frame = 6
            # Estimate the remaining time based on the average time per frame
            estimated_remaining_time = remaining_frames * avg_time_per_frame


            WIDGETS[0] = FormatLabel(
                "Processing frame {0}/{1}. Estimated time remaining: {2} seconds".format(
                    self.processed_frames, int(frameAmount), int(estimated_remaining_time)
                )
            )
            for i in range(N):
                pbar.update(self.limit(progressPercentage))
            
            if self.args.jobid is not None:
                self.update_progress(int(progressPercentage), int(estimated_remaining_time))

    def main(self):
 
        if self.args.models:
            models = self.api.get_sd_models()
            current_model = self.api.util_get_current_model()
            print("Current model: {0}".format(current_model))
            print("\n\nModels installed")
            models_json = json.dumps(self.api.get_sd_models(), indent=2)

            if (self.args.debug is True):           
                print(models_json)
            else:
                for item in json.loads(models_json):
                    print(item['title'])

            print("\n\nLoras installed")
            models_json = json.dumps(self.api.get_loras(), indent=2)

            if (self.args.debug is True):           
                print(models_json)
            else:
                for item in json.loads(models_json):
                    print(item['name'])            
            print("\n\nEmbeddings installed")

            if (self.args.debug is True):           
                models_json = json.dumps(self.api.get_embeddings(), indent=2)
                print(models_json)
            else:
                print(json.dumps(self.api.get_embeddings(),indent=2))

            print("Scripts installed")
            print(json.dumps(self.api.get_scripts(),indent=2))
            sys.exit(0)
        if self.args.sysinfo:
            mem = self.api.get_memory()
            apioptions = self.api.get_options()
            print("Memory info")
            print(json.dumps(mem, indent=2))
            print("\n\nOptions")
            print(json.dumps(apioptions, indent=2))
            print("CMD flags")
            print(json.dumps(self.api.get_cmd_flags(), indent=2))

            sys.exit(0)            

        if self.args.controlnetinfo:
            ver = self.api.controlnet_version()
            models = self.api.controlnet_model_list()
            modules = self.api.controlnet_module_list()
            print("Version: {0}".format(ver))
            print("\n\nInstalled modules")
            print(modules)
            print("\n\nInstalled models")
            print(models)
            sys.exit(0)
        if self.args.progress:
            progress = self.api.get_progress()
            print (progress)
            sys.exit(0)
        if self.args.interrupt:
            self.api.interrupt()
        if self.args.wait:
            self.api.util_wait_for_ready()

        if self.args.attachaudio:
            self.extractAudio(self.args.path);
            self.attachAudio(self.args.outfile);
            sys.exit(0)
                
        if self.args.model:
            model = self.args.model
            currentModel = self.api.util_get_current_model()
            if currentModel != model:
                print("Changing model from "+currentModel+ " to "+ model)
                self.api.util_set_model(model)

        path = self.args.path

        if self.args.seed:
            seed = self.args.seed
        else:
            self.args.seed = random.randint(1, 2147483647)

        fps, duration, path = self._probe_metadata(path)
        preview_img_fullpath = False
        preview_img_url = False
        animated_preview_img_url = False
        if (self.args.preview_img or self.args.preview_animation):

            if (len(self.args.preview_animation) > 0):
                animated_preview_img_basename = os.path.basename(self.args.preview_animation);                
                animated_preview_img_url = "{0}/{1}".format(self.args.preview_url, animated_preview_img_basename)
                animated_preview_img_fullpath = self.args.preview_animation

            if (len(self.args.preview_img) > 0):
                preview_img_basename = os.path.basename(self.args.preview_img)
                preview_img_url = "{0}/{1}".format(self.args.preview_url, preview_img_basename)
                preview_img_fullpath = self.args.preview_img
                print("Using "+preview_img_fullpath+" for preview")
                print("Using "+preview_img_url+" for preview image")

            if (animated_preview_img_url is not False):
                print("Using "+animated_preview_img_url+" for animated preview url")
                print("Using "+animated_preview_img_fullpath+" for animated path")
                

        if fps is None or duration is None:
            print("Unable to extract FPS and duration from the video file.")
            if self.args.jobid is not None:
                self.update_status('error')
                sys.exit(1)
       
        frameAmount = math.ceil(fps*duration)
        startFrame = 0
        
        if self.args.limit_frames_amount > 0:
            frameAmount = self.args.limit_frames_amount
            self.debugPrint('Limiting amount of frames to {0}'.format(frameAmount))
        if self.args.limit_frames_start > 0:
            startFrame = self.args.limit_frames_start    
            self.debugPrint('Starting from frame #{0}'.format(startFrame))
 
        if self.args.preview_url is not None and frameAmount > (options.get('preview_frame_count')+options.get('preview_start_frame')):
            frameAmount = options.get('preview_frame_count')
            startFrame = options.get('preview_start_frame')

        framelist = self.getFrames()
        self.frame_times = []  # List to store time taken to process each frame
        self.processed_frames = 0 
        N = 100
        previewWritten = False
        pbar = ProgressBar(widgets=WIDGETS, maxval=N).start()
        self.updateProgress(frameAmount, starttime, N, pbar, 0)
        # Init controlnet units if any configured
        self.initControlnetUnits() 
        self.debugPrint("Starting from frame {0} with {1} frames".format(startFrame, frameAmount))
        workdir = '/opt/jobs/{0}'.format(self.args.jobid)
        try:
            os.mkdir(workdir)
        except OSError as error:
            print("Error!"+error.strerror)
           
        print("Using {0} as work directory".format(workdir))
        counter = 0
        animated_img_file_paths = []

        for idx, frame in enumerate(framelist):
            frame_start_time = time.time() 
            status = self.get_status()
        
            if status == "aborted":
                print("Job has been aborted.")
                sys.exit(0)


            if (counter < int(startFrame) or counter >= int(frameAmount+startFrame)):
                counter += 1
                continue
            
            counter += 1

            ## Run the frame through stable diffusion
            processedFrame = self.processFrame(frame)

            if (preview_img_url is not False and previewWritten is False):
                print("Writing {0}".format(preview_img_fullpath))

                iio.imwrite(preview_img_fullpath, processedFrame)
                self.update_preview_img(preview_img_url)
                previewWritten = True
            
            if self.args.limit_frames_amount == 0:
                sequence = "{:04d}".format(int(counter))
                framefile = "{0}/frame-{1}.png".format(workdir,sequence)
                iio.imwrite(framefile, processedFrame)

            ## Write the frame to final file
                            
            self.updateProgress(frameAmount, frame_start_time, N, pbar, 1)
            if animated_preview_img_url is not False:
                animated_img_seq_file = '{0}_{1}.png'.format(
                os.path.splitext(self.args.preview_animation)[0], datetime.timestamp(datetime.now()))
                animated_img_file_paths.append(animated_img_seq_file)
                self.debugPrint("Writing frame to "+animated_img_seq_file)

                iio.imwrite(animated_img_seq_file, processedFrame)
                self.make_ping_pong_gif(animated_img_seq_file, self.args.preview_animation)
                if self.args.jobid:
                    animated_url_timestamped = '{0}?{1}'.format(animated_preview_img_url, counter)
                    self.update_preview_animation(animated_url_timestamped)

        if preview_img_url is not False and self.args.jobid is not None:
            preview_url_timestamped = "{0}?{1}".format(preview_img_url, datetime.timestamp(datetime.now()))
            print("Updating preview to "+preview_url_timestamped)
            self.update_preview_img(preview_url_timestamped)
        


        if self.args.limit_frames_amount > 0:
            for filename in self.animated_frames:
                if os.path.isfile(filename) is True:
                    os.remove(filename)
            statustext = 'preview'
        else:
            ffmpeg.input("{0}/frame-%04d.png".format(workdir), pattern_type='glob', framerate=self.args.fps).filter('deflicker', mode='pm', size=10).filter('scale', size='hd1080', force_original_aspect_ratio='increase').output(self.args.outfile, crf=20, fps=self.args.fps, video_bitrate=2500, preset='slower', movflags='faststart', pix_fmt='yuv420p').run(overwrite_output=True)
            self.extractAudio(self.args.path)
            self.attachAudio(self.args.outfile)
            if os.path.isfile(self.args.outfile) is True:
                statustext = 'finished'

        self.update_status(statustext)


# Parse arguments

parser = argparse.ArgumentParser(
    description='Videoprocessor for Codename Mage')
parser.add_argument('path', type=str,
                    help='the path to the video file')
parser.add_argument('--unit1_params', type=str, help='Parameters for Controlnet unit 1 in the format "--unit1_params=\'module=hed, model=control_v11p_sd15_softedge [a8575a2a], weight=1.0, lowvram=false, pixel_perfect=true, loopback=True\'"')
parser.add_argument('--unit2_params', type=str, help='Parameters for Controlnet unit 2 in the format "--unit2_params=\'module=none, weight=1.5, lowvram=False, pixel_perfect=True, resize_mode=Crop and Resize, control_mode=Balanced, model=diff_control_sd15_temporalnet_fp16 [adc6bd97], loopback=True\'"')
parser.add_argument('--unit3_params', type=str, help='Parameters for Controlnet unit 3 in the format "--unit3_params=\'module=depth, weight=1.5, lowvram=False, pixel_perfect=True, resize_mode=Crop and Resize, control_mode=Balanced, model=diff_control_sd15_temporalnet_fp16 [adc6bd97], loopback=True\'"')

parser.add_argument('--prompt', type=str,
                    help='words to emphasize when generating frames')
parser.add_argument('--negative_prompt', type=str,
                    help='words to de-emphasize when generating frames')
parser.add_argument('--api_host', type=str,
                    help='Stable Diffusion API host (overrides default setting)')
parser.add_argument('--api_hosts', type=str,
                    help='Comma-separated Stable Diffusion API hosts to load balance requests')
parser.add_argument('--api_port', type=str, default=options.get("api_port"),
                    help='Stable Diffusion API port (default: 7860)')
parser.add_argument('--outfile', default='out.mp4', type=str,
                    help='filename for the generated file')
parser.add_argument('--preview_url', type=str,
                    help='Set preview url')
parser.add_argument('--preview_img', type=str,
                    help='Set preview image url')
parser.add_argument('--preview_animation', type=str,
                    help='Set animation image url')
parser.add_argument('--sampler', type=str, default='Euler a',help='which sampler to use (default: Euler a)')
parser.add_argument('--denoising_strength', type=float, default=0.75,
                    help='how severely to rewrite the video frame (0: return the same frame, 1: return a wholly new '
                         'frame) (default: 0.75)')
parser.add_argument('--seed', type=int,
                    help='the random seed to use for generation (defaults to randomly selected)')
parser.add_argument('--steps', type=int, default=50,
                    help='the number of iterations used in video frame generation (default: 50)')
parser.add_argument('--cfg_scale', type=float, default=7.0,
                    help='how much freedom the video frame generation has to deviate from the prompt (default: 7.0; '
                         'higher nppumbers = more deviation')
parser.add_argument('--width', type=int, default=512,
                    help='output width for the generated video (default: 512)')
parser.add_argument('--height', type=int, default=512,
                    help='output height for the generated video (default: 512)')
parser.add_argument('--restore_faces', action="store_true",
                    help='run face restoration on the generated video frames')
parser.add_argument('--tiling', action="store_true",
                    help='generate video frames which will (independently) tile seamlessly')
parser.add_argument('--model', type=str,
                    help='Set model to be used in generation')
parser.add_argument('--jobid', type=int,
                    help='Set job id to update')
parser.add_argument('--fps', type=int,
                    help='Set fps for the video')
parser.add_argument('--duration', type=int,
                    help='Set duration for the video')
parser.add_argument('--limit_frames_amount', type=int, default=0, help='Set limit for the frames to process')
parser.add_argument('--limit_frames_start', type=int, default=0, help='Set start frame to start processing')
parser.add_argument('--interrupt',  action="store_true",
                    help='Interrupt whatever process is running currently')
parser.add_argument('--wait',  action="store_true",
                    help='Wait for current jobs to finish')
parser.add_argument('--models', action="store_true",
                    help='Get info about installed models')
parser.add_argument('--sysinfo', action="store_true",
                    help='Get info about system')
parser.add_argument('--controlnetinfo', action="store_true",
                    help='Get info about controlnet')
parser.add_argument('--progress', action="store_true",
                    help='Get info about progrress')
parser.add_argument('--attachaudio', action="store_true",
                    help='Attach audio from source to target, and exit')
parser.add_argument('--debug', action="store_true",
                    help='Print debug info')
args = parser.parse_args()

# Create a VideoProcessor instance and call the main function
processor = VideoProcessor(args)
processor.main()
