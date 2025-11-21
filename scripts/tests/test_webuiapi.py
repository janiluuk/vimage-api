import base64
import sys
from io import BytesIO
from pathlib import Path
from typing import List

import pytest
from PIL import Image

# Make the scripts directory importable
sys.path.append(str(Path(__file__).resolve().parents[1]))

import webuiapi  # noqa: E402


def make_base64_image() -> str:
    """Create a tiny base64-encoded PNG for fake API responses."""
    buffer = BytesIO()
    Image.new("RGB", (1, 1), color="red").save(buffer, format="PNG")
    return base64.b64encode(buffer.getvalue()).decode()


class DummyResponse:
    def __init__(self, url: str, payload: dict):
        self.url = url
        self.status_code = 200
        self._payload = payload
        self.text = ""

    def json(self):
        return self._payload


class DummySession:
    def __init__(self, payload: dict):
        self.post_calls: List = []
        self.get_calls: List = []
        self.payload = payload
        self.auth = None

    def post(self, url, json=None):
        self.post_calls.append((url, json))
        return DummyResponse(url, self.payload)

    def get(self, url):
        self.get_calls.append(url)
        # ensure check_controlnet works without KeyError
        if url.endswith("/scripts"):
            return DummyResponse(url, {"txt2img": []})
        return DummyResponse(url, {})


@pytest.fixture()
def dummy_session(monkeypatch):
    created_sessions: List[DummySession] = []
    payload = {"images": [make_base64_image()], "parameters": {}, "info": "{}"}

    def session_factory():
        session = DummySession(payload)
        created_sessions.append(session)
        return session

    monkeypatch.setattr(webuiapi.requests, "Session", session_factory)
    return created_sessions


def test_round_robin_baseurls(dummy_session):
    api = webuiapi.WebUIApi(baseurl=["http://host-a/sdapi/v1", "http://host-b/sdapi/v1"])
    session = dummy_session[0]

    api.txt2img(prompt="hello")
    api.txt2img(prompt="world")

    assert session.post_calls[0][0].startswith("http://host-a/sdapi/v1/txt2img")
    assert session.post_calls[1][0].startswith("http://host-b/sdapi/v1/txt2img")


def test_custom_get_can_skip_api_prefix(dummy_session):
    api = webuiapi.WebUIApi(baseurl=["http://host-c/sdapi/v1"])
    session = dummy_session[0]

    api.custom_get("controlnet/version", baseurl=True)
    api.custom_get("controlnet/version", baseurl=False)

    assert session.get_calls[-2].endswith("/sdapi/v1/controlnet/version")
    assert session.get_calls[-1].endswith("/controlnet/version")
