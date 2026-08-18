import socket

import httpx
import pytest

from sales_spy_scrapers.config import Settings
from sales_spy_scrapers.domain.errors import (
    ResponseTooLargeError,
    RobotsDeniedError,
    UnsafeTargetError,
    UnsupportedContentError,
)
from sales_spy_scrapers.infrastructure import http_client as http_module
from sales_spy_scrapers.infrastructure.http_client import SafeHttpClient


async def resolve_public_host(host: str, port: int | None) -> str:
    return "203.0.113.10"


@pytest.mark.asyncio
async def test_fetch_html_respects_robots_and_reads_bounded_content(
    settings: Settings,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(http_module, "_resolve_public_host", resolve_public_host)

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/robots.txt":
            return httpx.Response(200, text="User-agent: *\nAllow: /", request=request)
        return httpx.Response(
            200,
            headers={"content-type": "text/html"},
            text="<html>ok</html>",
            request=request,
        )

    async with SafeHttpClient(settings, httpx.MockTransport(handler)) as client:
        page = await client.fetch_html("https://example.com/")

    assert page.status_code == 200
    assert page.content == b"<html>ok</html>"


@pytest.mark.asyncio
async def test_robots_denial_blocks_page_request(
    settings: Settings,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(http_module, "_resolve_public_host", resolve_public_host)
    paths: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        paths.append(request.url.path)
        return httpx.Response(200, text="User-agent: *\nDisallow: /private", request=request)

    async with SafeHttpClient(settings, httpx.MockTransport(handler)) as client:
        with pytest.raises(RobotsDeniedError):
            await client.fetch_html("https://example.com/private")

    assert paths == ["/robots.txt"]


@pytest.mark.asyncio
async def test_oversized_response_is_rejected_without_retry(
    settings: Settings,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(http_module, "_resolve_public_host", resolve_public_host)
    calls = 0

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        if request.url.path == "/robots.txt":
            return httpx.Response(404, request=request)
        calls += 1
        return httpx.Response(
            200,
            headers={"content-type": "text/html", "content-length": "99999"},
            request=request,
        )

    async with SafeHttpClient(settings, httpx.MockTransport(handler)) as client:
        with pytest.raises(ResponseTooLargeError):
            await client.fetch_html("https://example.com/")

    assert calls == 1


@pytest.mark.asyncio
async def test_transient_server_errors_are_retried(
    settings: Settings,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(http_module, "_resolve_public_host", resolve_public_host)

    async def no_sleep(_: float) -> None:
        return None

    monkeypatch.setattr(__import__("asyncio"), "sleep", no_sleep)
    calls = 0

    def handler(request: httpx.Request) -> httpx.Response:
        nonlocal calls
        if request.url.path == "/robots.txt":
            return httpx.Response(404, request=request)
        calls += 1
        if calls < 3:
            return httpx.Response(503, headers={"content-type": "text/html"}, request=request)
        return httpx.Response(
            200, headers={"content-type": "text/html"}, text="ok", request=request
        )

    async with SafeHttpClient(settings, httpx.MockTransport(handler)) as client:
        page = await client.fetch_html("https://example.com/")

    assert page.content == b"ok"
    assert calls == 3


@pytest.mark.asyncio
async def test_redirect_is_validated_before_private_target_request(
    settings: Settings,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    requested_hosts: list[str] = []

    async def validate(host: str, port: int | None) -> str:
        if host == "127.0.0.1":
            raise UnsafeTargetError("private target")
        return "203.0.113.10"

    monkeypatch.setattr(http_module, "_resolve_public_host", validate)

    def handler(request: httpx.Request) -> httpx.Response:
        requested_hosts.append(request.url.host)
        if request.url.path == "/robots.txt":
            return httpx.Response(404, request=request)
        return httpx.Response(302, headers={"location": "http://127.0.0.1/admin"}, request=request)

    async with SafeHttpClient(settings, httpx.MockTransport(handler)) as client:
        with pytest.raises(UnsafeTargetError):
            await client.fetch_html("https://example.com/")

    assert requested_hosts == ["203.0.113.10", "203.0.113.10"]


@pytest.mark.asyncio
async def test_non_html_content_is_rejected(
    settings: Settings,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    monkeypatch.setattr(http_module, "_resolve_public_host", resolve_public_host)

    def handler(request: httpx.Request) -> httpx.Response:
        if request.url.path == "/robots.txt":
            return httpx.Response(404, request=request)
        return httpx.Response(
            200, headers={"content-type": "image/png"}, content=b"png", request=request
        )

    async with SafeHttpClient(settings, httpx.MockTransport(handler)) as client:
        with pytest.raises(UnsupportedContentError):
            await client.fetch_html("https://example.com/image")


@pytest.mark.asyncio
async def test_private_dns_resolution_is_rejected(monkeypatch: pytest.MonkeyPatch) -> None:
    loop = __import__("asyncio").get_running_loop()

    async def fake_getaddrinfo(*args: object, **kwargs: object) -> list[tuple[object, ...]]:
        return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("10.0.0.1", 443))]

    monkeypatch.setattr(loop, "getaddrinfo", fake_getaddrinfo)
    with pytest.raises(UnsafeTargetError):
        await http_module._resolve_public_host("internal.example", 443)
