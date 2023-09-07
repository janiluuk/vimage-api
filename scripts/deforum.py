#!/usr/bin/python3
import argparse
from PIL import Image
import requests
import json
import base64
import sys


class DeforumController():
    def __init__(self, args) -> None:
        self.args = args
        self.url = ''
        self.host = "http://localhost"
        self.port = 7860
    
        self.default_settings_file='./deforum_default_settings.json'
        self.settings = {
            "prompts": {},
        }
        
    def main(self):
        """
        Main execution of the DeforumController.
        """
        self.port = self.port if self.args.port is None else self.args.port
        self.host = self.host if self.args.host is None else "http://{0}".format(self.args.host)
        
        self.url = "{0}:{1}".format(self.host, self.port)
        self.parse_prompts()
        self.load_settings_from_file(self.default_settings_file)
        if self.args.delete_job:
            res = self.delete_deforum_job(self.args.delete_job)
            print(json.dumps(res, indent=2))
        if self.args.json_settings:
            try:
                self.merge_json_settings(args.json_settings)
            
            except Exception as e:
                print("Error while merging JSON settings:", str(e))
                return
        if self.args.show: 
            val = self.find_value(args.show)
            if val:
                print("{0} : {1}".format(args.show, val))
            else:
                print("Didnt find value {0}".format(args.show))
            exit(0)
        if self.args.show_job:
            res = self.get_deforum_job(args.show_job)
            print(json.dumps(res, indent=2))             
        self.settings["init_images"] = False if self.args.init_img is None else ''

        self.settings["use_init"] = None if self.args.init_img is None else True
        self.settings["init_image"] = None if self.args.init_img is None else self.args.init_img
        self.settings["animation_prompts_negative"] = "" if self.args.negative_prompts is None else self.args.negative_prompts
        self.settings["seed"] = -1 if self.args.seed is None else self.args.seed
        self.settings["sampler"] = "Euler a" if self.args.sampler is None else self.args.sampler
        # Strength might be a different key in deforum
        self.settings["strength"] = 0.4 if self.args.strength is None else self.args.strength
        self.settings["fps"] = 25 if self.args.strength is None else self.args.strength
        self.settings["steps"] = 20 if self.args.steps is None else self.args.steps
        self.settings["animation_mode"] = "2D" if self.args.animation_mode is None else self.args.animation_mode
        self.settings["max_frames"] = 100 if self.args.max_frames is None else self.args.max_frames
        # Get the output W and H based on input img.
        self.parse_image()
        #Print all argumanets to terminal
        if self.args.display:
            self.display_results(args.display)
            exit(0)
        # Start the job via defourm api.
        if self.args.start:
            print("Starting job")
            self.start_job()
            exit(0)    

    def parse_prompts(self):
        
        if self.args.prompts is not None and self.args.prompts.__contains__(':'):
            prompts = self.args.prompts.split(';')

            for prompt in prompts:
                keyframe, text = prompt.split(':')
                self.settings["prompts"][keyframe] = text

    def parse_image(self):
        image_path = self.args.init_img

        if image_path is not None:
            try:
                with Image.open(image_path) as img:
                    width, height = img.size
                    self.settings["W"] = width
                    self.settings["H"] = height
                    self.encode_image_to_base64()
            except Exception as e:
                print("Error while getting image dimensions:", e)

    def load_settings_from_file(self, file_path):
        try:
            with open(file_path, 'r') as file:
                loaded_settings = json.load(file)
                for key, value in loaded_settings.items():
                    if key not in self.settings:
                        self.settings[key] = value
        except Exception as e:
            print("Error while loading settings from file:", e)
    
    def merge_json_settings(self, json_str):
        try:
            loaded_settings = json.loads(json_str)
            for key, value in loaded_settings.items():
                self.settings[key] = value
        except Exception as e:
            print("Error while merging JSON settings:", e)
    def find_value (self, name):
        if (self.settings[name] is not None):
            return self.settings[name]
        return None
    
    def display_results(self, method_name):
        methods_map = {
            "batchids": self.get_deforum_batch_ids,
            "batches": self.get_deforum_batches,
            "jobs": self.get_deforum_jobs,
            "settings": self.print_settings_as_json
        }
        method = methods_map.get(method_name)
        if method:
            result = method()
            print(json.dumps(result, indent=4))
        else:
            print(f"Method {method_name} not found.")

    def encode_image_to_base64(self):
        image_path = self.args.init_img
        
        if image_path:
            try:
                with open(image_path, "rb") as image_file:
                    encoded_image = base64.b64encode(image_file.read()).decode("utf-8")
                    self.settings["init_image"] = encoded_image
            except Exception as e:
                print("Error while encoding image to base64:", e)

    def delete_deforum_job(self, job_id):
        endpoint = f"{self.url}/deforum_api/batches/{job_id}"
        try:
            res = requests.delete(endpoint)
            return res.body
        except Exception as e:
            print("Error while deleting job:", e)
            return None
    def start_job(self):
        """
        Start the job by sending a POST request with settings to the Deforum API.
        """
        payload = {"deforum_settings": self.settings}
        res = requests.post(url=f'{self.url}/deforum_api/batches', json=payload)
        json.dumps(res.json(), indent=4)
        if res.status_code == 202:
            print("Job added to the queue!")
        else:
            print("Failed to add job to queue:{0}".format(res.text))
            exit(1)

    def print_settings_as_json(self):
        """
        Print the settings dictionary as a formatted JSON string.
        """
        formatted_json = json.dumps(self.settings, indent=4)
        print(formatted_json)

    def get_deforum_batch_ids(self):
        """
        Retrieve a list of DeForum batch IDs.

        :return: List of batch IDs or None if not found.
        """
        res = requests.get(url=f'{self.url}/deforum_api/batch') 
        try:
            if (res.json()['detail'] == 'Not Found'):
                return None
        
        except Exception as e:
            print("Error while merging JSON settings:", str(e))
            return

        batch_ids = [id for id in res.json()]
        return batch_ids

    def get_deforum_batches(self):
        res = requests.get(url=f'{self.url}/deforum_api/batch') 
        try:
            if (res.json()['detail'] == 'Not Found'):
                return None
        
        except Exception as e:
            print("Error while merging JSON settings:", str(e))
            return

            return res.json()
        
    def get_deforum_job_ids(self):
        res = requests.get(url=f'{self.url}/deforum_api/jobs') 
        try:
            if (res.json()['detail'] == 'Not Found'):
                return None
        
        except Exception as e:
            print("Error while merging JSON settings:", str(e))
            return

            job_ids = [id for id in res.json()]
            return job_ids

    def get_deforum_job(self, id):
        res = requests.get(url=f'{self.url}/deforum_api/jobs/{id}') 

        return res.json()

    def get_deforum_jobs(self):
        res = requests.get(url=f'{self.url}/deforum_api/jobs') 
        response = dict()

        r = res.json()

        for job in res.json():
            for key in job:
                r[job]['deforum_settings'] = ''
                print(r[job])
                
        exit(0)
# Main execution of the DeforumController
parser = argparse.ArgumentParser(description="Deforum controller for codename Vimage")
parser.add_argument('--prompts', type=str, help="Prompts in the format 'keyframe:prompt;keyframe:prompt;...'")
parser.add_argument('--host', type=str, help="Hostname to connect to")
parser.add_argument('--port', type=int, default=7860, help="Port to connect to")

parser.add_argument('--json_settings', type=str, help="JSON string to overwrite default settings.")

parser.add_argument('--show', type=str, help="Show resulting key from settings. e.g. 'steps'")
parser.add_argument('--init_img', type=str, help="The path for input image")
parser.add_argument("--negative_prompts", type=str, help="Negative prompt keywords")
parser.add_argument("--seed", type=str, help="Seed for generation")
parser.add_argument("--fps", type=int, help="FPS")
parser.add_argument('--sampler', type=str, default='Euler a',help='which sampler to use (default: Euler a)')
parser.add_argument('--strength', default=0.4, help="Denoising strength")
parser.add_argument('--steps', type=int, default=20,
                    help='the number of iterations used in video frame generation (default: 20)')
parser.add_argument("--animation_mode", type=str, default="2D", help="2D or 3D")
parser.add_argument("--max_frames", type=int, help="Length of the video")
parser.add_argument('--start', action="store_true",help="Start the job")
parser.add_argument('--display', type=str, choices=["batchids", "batches", "jobs", "settings"], 
                    help="Display results of the specified method in a pretty-printed JSON format.")
parser.add_argument('--show_job', type=str, help="ID of the job to be listed")
parser.add_argument('--delete_job', type=str, help="ID of the job to be deleted.")
args = parser.parse_args()

if len( sys.argv ) < 2:
    parser.print_help(sys.stderr)    

deforum = DeforumController(args)


deforum.main()
