class ScraperError(Exception):
    """Base class for expected scraper failures."""


class InvalidDomainError(ScraperError):
    pass


class RobotsDeniedError(ScraperError):
    pass


class FetchError(ScraperError):
    pass


class RetryableFetchError(FetchError):
    def __init__(self, message: str, retry_after_seconds: float | None = None) -> None:
        super().__init__(message)
        self.retry_after_seconds = retry_after_seconds


class ResponseTooLargeError(FetchError):
    pass


class UnsupportedContentError(FetchError):
    pass


class UnsafeTargetError(FetchError):
    pass


class SchemaCompatibilityError(ScraperError):
    pass


class ClaimLostError(ScraperError):
    pass


class DataConflictError(ScraperError):
    pass
