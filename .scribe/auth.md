# Authenticating requests

This API uses **Bearer token authentication**.

After registering or logging in, you receive a token in the response:

```json
{
    "data": {
        "token": "1|xxxxxxxxxxxxxxxxxxxxxxxx"
    }
}
```

Include this token in the `Authorization` header of every protected request:

```
Authorization: Bearer 1|xxxxxxxxxxxxxxxxxxxxxxxx
```

Tokens do not expire automatically. They are invalidated when you call `POST /api/v1/auth/logout`.
