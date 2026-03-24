<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>Sales-Spy-API API Documentation</title>

    <link href="https://fonts.googleapis.com/css?family=Open+Sans&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('/vendor/scribe/css/theme-default.style.css') }}" media="screen">
    <link rel="stylesheet" href="{{ asset('/vendor/scribe/css/theme-default.print.css') }}" media="print">

    <script src="https://cdn.jsdelivr.net/npm/lodash@4.17.10/lodash.min.js"></script>

    <link rel="stylesheet" href="https://unpkg.com/@highlightjs/cdn-assets@11.6.0/styles/obsidian.min.css">
    <script src="https://unpkg.com/@highlightjs/cdn-assets@11.6.0/highlight.min.js"></script>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jets/0.14.1/jets.min.js"></script>

    <style id="language-style">
        /* starts out as display none and is replaced with js later  */
        body .content .bash-example code {
            display: none;
        }

        body .content .javascript-example code {
            display: none;
        }
    </style>

    <script>
        var tryItOutBaseUrl = "http://localhost:8000";
        var useCsrf = Boolean();
        var csrfUrl = "/sanctum/csrf-cookie";
    </script>
    <script src="{{ asset('/vendor/scribe/js/tryitout-5.9.0.js') }}"></script>

    <script src="{{ asset('/vendor/scribe/js/theme-default-5.9.0.js') }}"></script>

</head>

<body data-languages="[&quot;bash&quot;,&quot;javascript&quot;]">

    <a href="#" id="nav-button">
        <span>
            MENU
            <img src="{{ asset('/vendor/scribe/images/navbar.png') }}" alt="navbar-image" />
        </span>
    </a>
    <div class="tocify-wrapper">

        <div class="lang-selector">
            <button type="button" class="lang-button" data-language-name="bash">bash</button>
            <button type="button" class="lang-button" data-language-name="javascript">javascript</button>
        </div>

        <div class="search">
            <input type="text" class="search" id="input-search" placeholder="Search">
        </div>

        <div id="toc">
            <ul id="tocify-header-introduction" class="tocify-header">
                <li class="tocify-item level-1" data-unique="introduction">
                    <a href="#introduction">Introduction</a>
                </li>
                <ul id="tocify-subheader-introduction" class="tocify-subheader">
                    <li class="tocify-item level-2" data-unique="response-format">
                        <a href="#response-format">Response Format</a>
                    </li>
                    <li class="tocify-item level-2" data-unique="http-status-codes">
                        <a href="#http-status-codes">HTTP Status Codes</a>
                    </li>
                    <li class="tocify-item level-2" data-unique="credits-system">
                        <a href="#credits-system">Credits System</a>
                    </li>
                </ul>
            </ul>
            <ul id="tocify-header-authenticating-requests" class="tocify-header">
                <li class="tocify-item level-1" data-unique="authenticating-requests">
                    <a href="#authenticating-requests">Authenticating requests</a>
                </li>
            </ul>
            <ul id="tocify-header-authentication" class="tocify-header">
                <li class="tocify-item level-1" data-unique="authentication">
                    <a href="#authentication">Authentication</a>
                </li>
                <ul id="tocify-subheader-authentication" class="tocify-subheader">
                    <li class="tocify-item level-2" data-unique="authentication-POSTapi-v1-auth-register">
                        <a href="#authentication-POSTapi-v1-auth-register">Register a new user</a>
                    </li>
                    <li class="tocify-item level-2" data-unique="authentication-POSTapi-v1-auth-login">
                        <a href="#authentication-POSTapi-v1-auth-login">Login</a>
                    </li>
                    <li class="tocify-item level-2" data-unique="authentication-POSTapi-v1-auth-logout">
                        <a href="#authentication-POSTapi-v1-auth-logout">Logout</a>
                    </li>
                    <li class="tocify-item level-2" data-unique="authentication-GETapi-v1-auth-me">
                        <a href="#authentication-GETapi-v1-auth-me">Get authenticated user</a>
                    </li>
                </ul>
            </ul>
            <ul id="tocify-header-general" class="tocify-header">
                <li class="tocify-item level-1" data-unique="general">
                    <a href="#general">General</a>
                </li>
                <ul id="tocify-subheader-general" class="tocify-subheader">
                    <li class="tocify-item level-2" data-unique="general-GETapi-v1-health">
                        <a href="#general-GETapi-v1-health">Health Check</a>
                    </li>
                </ul>
            </ul>
            <ul id="tocify-header-oauth" class="tocify-header">
                <li class="tocify-item level-1" data-unique="oauth">
                    <a href="#oauth">OAuth</a>
                </li>
                <ul id="tocify-subheader-oauth" class="tocify-subheader">
                    <li class="tocify-item level-2" data-unique="oauth-GETapi-v1-auth--provider--redirect">
                        <a href="#oauth-GETapi-v1-auth--provider--redirect">OAuth Redirect</a>
                    </li>
                    <li class="tocify-item level-2" data-unique="oauth-GETapi-v1-auth--provider--callback">
                        <a href="#oauth-GETapi-v1-auth--provider--callback">OAuth Callback</a>
                    </li>
                </ul>
            </ul>
        </div>

        <ul class="toc-footer" id="toc-footer">
            <li style="padding-bottom: 5px;"><a href="{{ route('scribe.postman') }}">View Postman collection</a></li>
            <li style="padding-bottom: 5px;"><a href="{{ route('scribe.openapi') }}">View OpenAPI spec</a></li>
            <li><a href="http://github.com/knuckleswtf/scribe">Documentation powered by Scribe ✍</a></li>
        </ul>

        <ul class="toc-footer" id="last-updated">
            <li>Last updated: March 24, 2026</li>
        </ul>
    </div>

    <div class="page-wrapper">
        <div class="dark-box"></div>
        <div class="content">
            <h1 id="introduction">Introduction</h1>
            <p>Welcome to the <strong>Sales-Spy API</strong> — e-commerce lead intelligence for serious sales teams.</p>
            <p>Base URL: <code>https://sales-spy-api-production.up.railway.app</code></p>
            <hr />
            <h2 id="response-format">Response Format</h2>
            <p>Every endpoint returns the same JSON structure:</p>
            <p><strong>Success:</strong></p>
            <pre><code class="language-json">{
  "success": true,
  "message": "Human readable message",
  "data": { ... }
}</code></pre>
            <p><strong>Error:</strong></p>
            <pre><code class="language-json">{
  "success": false,
  "message": "What went wrong",
  "errors": { ... }
}</code></pre>
            <p><strong>Paginated:</strong></p>
            <pre><code class="language-json">{
  "success": true,
  "message": "...",
  "data": [ ... ],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "per_page": 25,
    "total": 243
  }
}</code></pre>
            <hr />
            <h2 id="http-status-codes">HTTP Status Codes</h2>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Meaning</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>200</td>
                        <td>Success</td>
                    </tr>
                    <tr>
                        <td>201</td>
                        <td>Created successfully</td>
                    </tr>
                    <tr>
                        <td>401</td>
                        <td>Unauthenticated — token missing or invalid</td>
                    </tr>
                    <tr>
                        <td>402</td>
                        <td>Insufficient credits</td>
                    </tr>
                    <tr>
                        <td>403</td>
                        <td>Unauthorized — you don't have permission</td>
                    </tr>
                    <tr>
                        <td>422</td>
                        <td>Validation failed — check the errors field</td>
                    </tr>
                    <tr>
                        <td>500</td>
                        <td>Server error</td>
                    </tr>
                </tbody>
            </table>
            <hr />
            <h2 id="credits-system">Credits System</h2>
            <p>Most data endpoints cost credits. Your balance is shown in every dashboard response.
                Costs per action:</p>
            <ul>
                <li>View store details: <strong>1 credit</strong></li>
                <li>Search results (per result): <strong>1 credit</strong></li>
                <li>Export to CSV (per row): <strong>2 credits</strong></li>
                <li>Deep scan a store: <strong>5 credits</strong></li>
            </ul>

            <h1 id="authenticating-requests">Authenticating requests</h1>
            <p>This API uses <strong>Bearer token authentication</strong>.</p>
            <p>After registering or logging in, you receive a token in the response:</p>
            <pre><code class="language-json">{
    "data": {
        "token": "1|xxxxxxxxxxxxxxxxxxxxxxxx"
    }
}</code></pre>
            <p>Include this token in the <code>Authorization</code> header of every protected request:</p>
            <pre><code>Authorization: Bearer 1|xxxxxxxxxxxxxxxxxxxxxxxx</code></pre>
            <p>Tokens do not expire automatically. They are invalidated when you call <code>POST
                    /api/v1/auth/logout</code>.</p>

            <h1 id="authentication">Authentication</h1>



            <h2 id="authentication-POSTapi-v1-auth-register">Register a new user</h2>

            <p>
            </p>

            <p>Creates a new user account and returns an auth token immediately.
                The user is assigned the free plan with 50 starter credits.</p>

            <span id="example-requests-POSTapi-v1-auth-register">
                <blockquote>Example request:</blockquote>


                <div class="bash-example">
                    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/v1/auth/register" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json" \
    --data "{
    \"name\": \"John Doe\",
    \"email\": \"john@example.com\",
    \"password\": \"password123\",
    \"password_confirmation\": \"password123\"
}"
</code></pre>
                </div>


                <div class="javascript-example">
                    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/auth/register"
);

const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
};

let body = {
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123"
};

fetch(url, {
    method: "POST",
    headers,
    body: JSON.stringify(body),
}).then(response =&gt; response.json());</code></pre>
                </div>

            </span>

            <span id="example-responses-POSTapi-v1-auth-register">
                <blockquote>
                    <p>Example response (201):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;success&quot;: true,
    &quot;message&quot;: &quot;Account created successfully&quot;,
    &quot;data&quot;: {
        &quot;token&quot;: &quot;1|xxxxxxxxxxxxxxxxxxxxxxxx&quot;,
        &quot;user&quot;: {
            &quot;id&quot;: 1,
            &quot;name&quot;: &quot;John Doe&quot;,
            &quot;email&quot;: &quot;john@example.com&quot;,
            &quot;plan&quot;: &quot;free&quot;,
            &quot;credits_balance&quot;: 50
        }
    }
}</code>
 </pre>
                <blockquote>
                    <p>Example response (422):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;success&quot;: false,
    &quot;message&quot;: &quot;Validation failed&quot;,
    &quot;errors&quot;: {
        &quot;email&quot;: [
            &quot;An account with this email already exists.&quot;
        ],
        &quot;password&quot;: [
            &quot;Password must be at least 8 characters.&quot;
        ]
    }
}</code>
 </pre>
            </span>
            <span id="execution-results-POSTapi-v1-auth-register" hidden>
                <blockquote>Received response<span id="execution-response-status-POSTapi-v1-auth-register"></span>:
                </blockquote>
                <pre class="json"><code id="execution-response-content-POSTapi-v1-auth-register"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
            </span>
            <span id="execution-error-POSTapi-v1-auth-register" hidden>
                <blockquote>Request failed with error:</blockquote>
                <pre><code id="execution-error-message-POSTapi-v1-auth-register">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
            </span>
            <form id="form-POSTapi-v1-auth-register" data-method="POST" data-path="api/v1/auth/register"
                data-authed="0" data-hasfiles="0" data-isarraybody="0" autocomplete="off"
                onsubmit="event.preventDefault(); executeTryOut('POSTapi-v1-auth-register', this);">
                <h3>
                    Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                        style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-tryout-POSTapi-v1-auth-register" onclick="tryItOut('POSTapi-v1-auth-register');">Try
                        it out ⚡
                    </button>
                    <button type="button"
                        style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-canceltryout-POSTapi-v1-auth-register"
                        onclick="cancelTryOut('POSTapi-v1-auth-register');" hidden>Cancel 🛑
                    </button>&nbsp;&nbsp;
                    <button type="submit"
                        style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-executetryout-POSTapi-v1-auth-register" data-initial-text="Send Request 💥"
                        data-loading-text="⏱ Sending..." hidden>Send Request 💥
                    </button>
                </h3>
                <p>
                    <small class="badge badge-black">POST</small>
                    <b><code>api/v1/auth/register</code></b>
                </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Content-Type"
                        data-endpoint="POSTapi-v1-auth-register" value="application/json" data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Accept"
                        data-endpoint="POSTapi-v1-auth-register" value="application/json" data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <h4 class="fancy-heading-panel"><b>Body Parameters</b></h4>
                <div style=" padding-left: 28px;  clear: unset;">
                    <b style="line-height: 2;"><code>name</code></b>&nbsp;&nbsp;
                    <small>string</small>&nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="name"
                        data-endpoint="POSTapi-v1-auth-register" value="John Doe" data-component="body">
                    <br>
                    <p>The user's full name. Example: <code>John Doe</code></p>
                </div>
                <div style=" padding-left: 28px;  clear: unset;">
                    <b style="line-height: 2;"><code>email</code></b>&nbsp;&nbsp;
                    <small>string</small>&nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="email"
                        data-endpoint="POSTapi-v1-auth-register" value="john@example.com" data-component="body">
                    <br>
                    <p>A valid, unique email address. Example: <code>john@example.com</code></p>
                </div>
                <div style=" padding-left: 28px;  clear: unset;">
                    <b style="line-height: 2;"><code>password</code></b>&nbsp;&nbsp;
                    <small>string</small>&nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="password"
                        data-endpoint="POSTapi-v1-auth-register" value="password123" data-component="body">
                    <br>
                    <p>Min 8 characters. Example: <code>password123</code></p>
                </div>
                <div style=" padding-left: 28px;  clear: unset;">
                    <b style="line-height: 2;"><code>password_confirmation</code></b>&nbsp;&nbsp;
                    <small>string</small>&nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="password_confirmation"
                        data-endpoint="POSTapi-v1-auth-register" value="password123" data-component="body">
                    <br>
                    <p>Must match password. Example: <code>password123</code></p>
                </div>
            </form>

            <h2 id="authentication-POSTapi-v1-auth-login">Login</h2>

            <p>
            </p>

            <p>Authenticate with email and password. Returns a Bearer token
                to use in all subsequent protected requests.</p>

            <span id="example-requests-POSTapi-v1-auth-login">
                <blockquote>Example request:</blockquote>


                <div class="bash-example">
                    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/v1/auth/login" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json" \
    --data "{
    \"email\": \"john@example.com\",
    \"password\": \"password123\"
}"
</code></pre>
                </div>


                <div class="javascript-example">
                    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/auth/login"
);

const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
};

let body = {
    "email": "john@example.com",
    "password": "password123"
};

fetch(url, {
    method: "POST",
    headers,
    body: JSON.stringify(body),
}).then(response =&gt; response.json());</code></pre>
                </div>

            </span>

            <span id="example-responses-POSTapi-v1-auth-login">
                <blockquote>
                    <p>Example response (200):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;success&quot;: true,
    &quot;message&quot;: &quot;Logged in successfully&quot;,
    &quot;data&quot;: {
        &quot;token&quot;: &quot;2|xxxxxxxxxxxxxxxxxxxxxxxx&quot;,
        &quot;user&quot;: {
            &quot;id&quot;: 1,
            &quot;name&quot;: &quot;John Doe&quot;,
            &quot;email&quot;: &quot;john@example.com&quot;,
            &quot;plan&quot;: &quot;free&quot;,
            &quot;credits_balance&quot;: 50
        }
    }
}</code>
 </pre>
                <blockquote>
                    <p>Example response (401):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;success&quot;: false,
    &quot;message&quot;: &quot;Invalid email or password&quot;,
    &quot;errors&quot;: null
}</code>
 </pre>
            </span>
            <span id="execution-results-POSTapi-v1-auth-login" hidden>
                <blockquote>Received response<span id="execution-response-status-POSTapi-v1-auth-login"></span>:
                </blockquote>
                <pre class="json"><code id="execution-response-content-POSTapi-v1-auth-login"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
            </span>
            <span id="execution-error-POSTapi-v1-auth-login" hidden>
                <blockquote>Request failed with error:</blockquote>
                <pre><code id="execution-error-message-POSTapi-v1-auth-login">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
            </span>
            <form id="form-POSTapi-v1-auth-login" data-method="POST" data-path="api/v1/auth/login" data-authed="0"
                data-hasfiles="0" data-isarraybody="0" autocomplete="off"
                onsubmit="event.preventDefault(); executeTryOut('POSTapi-v1-auth-login', this);">
                <h3>
                    Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                        style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-tryout-POSTapi-v1-auth-login" onclick="tryItOut('POSTapi-v1-auth-login');">Try it out
                        ⚡
                    </button>
                    <button type="button"
                        style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-canceltryout-POSTapi-v1-auth-login" onclick="cancelTryOut('POSTapi-v1-auth-login');"
                        hidden>Cancel 🛑
                    </button>&nbsp;&nbsp;
                    <button type="submit"
                        style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-executetryout-POSTapi-v1-auth-login" data-initial-text="Send Request 💥"
                        data-loading-text="⏱ Sending..." hidden>Send Request 💥
                    </button>
                </h3>
                <p>
                    <small class="badge badge-black">POST</small>
                    <b><code>api/v1/auth/login</code></b>
                </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Content-Type"
                        data-endpoint="POSTapi-v1-auth-login" value="application/json" data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Accept" data-endpoint="POSTapi-v1-auth-login"
                        value="application/json" data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <h4 class="fancy-heading-panel"><b>Body Parameters</b></h4>
                <div style=" padding-left: 28px;  clear: unset;">
                    <b style="line-height: 2;"><code>email</code></b>&nbsp;&nbsp;
                    <small>string</small>&nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="email" data-endpoint="POSTapi-v1-auth-login"
                        value="john@example.com" data-component="body">
                    <br>
                    <p>Your account email. Example: <code>john@example.com</code></p>
                </div>
                <div style=" padding-left: 28px;  clear: unset;">
                    <b style="line-height: 2;"><code>password</code></b>&nbsp;&nbsp;
                    <small>string</small>&nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="password"
                        data-endpoint="POSTapi-v1-auth-login" value="password123" data-component="body">
                    <br>
                    <p>Your password. Example: <code>password123</code></p>
                </div>
            </form>

            <h2 id="authentication-POSTapi-v1-auth-logout">Logout</h2>

            <p>
                <small class="badge badge-darkred">requires authentication</small>
            </p>

            <p>Invalidates the current Bearer token. The token cannot be
                used again after this call. The user must login again to get a new token.</p>

            <span id="example-requests-POSTapi-v1-auth-logout">
                <blockquote>Example request:</blockquote>


                <div class="bash-example">
                    <pre><code class="language-bash">curl --request POST \
    "http://localhost:8000/api/v1/auth/logout" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre>
                </div>


                <div class="javascript-example">
                    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/auth/logout"
);

const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
};


fetch(url, {
    method: "POST",
    headers,
}).then(response =&gt; response.json());</code></pre>
                </div>

            </span>

            <span id="example-responses-POSTapi-v1-auth-logout">
                <blockquote>
                    <p>Example response (200):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;success&quot;: true,
    &quot;message&quot;: &quot;Logged out successfully&quot;,
    &quot;data&quot;: null
}</code>
 </pre>
                <blockquote>
                    <p>Example response (401):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;message&quot;: &quot;Unauthenticated.&quot;
}</code>
 </pre>
            </span>
            <span id="execution-results-POSTapi-v1-auth-logout" hidden>
                <blockquote>Received response<span id="execution-response-status-POSTapi-v1-auth-logout"></span>:
                </blockquote>
                <pre class="json"><code id="execution-response-content-POSTapi-v1-auth-logout"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
            </span>
            <span id="execution-error-POSTapi-v1-auth-logout" hidden>
                <blockquote>Request failed with error:</blockquote>
                <pre><code id="execution-error-message-POSTapi-v1-auth-logout">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
            </span>
            <form id="form-POSTapi-v1-auth-logout" data-method="POST" data-path="api/v1/auth/logout" data-authed="1"
                data-hasfiles="0" data-isarraybody="0" autocomplete="off"
                onsubmit="event.preventDefault(); executeTryOut('POSTapi-v1-auth-logout', this);">
                <h3>
                    Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                        style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-tryout-POSTapi-v1-auth-logout" onclick="tryItOut('POSTapi-v1-auth-logout');">Try it
                        out ⚡
                    </button>
                    <button type="button"
                        style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-canceltryout-POSTapi-v1-auth-logout" onclick="cancelTryOut('POSTapi-v1-auth-logout');"
                        hidden>Cancel 🛑
                    </button>&nbsp;&nbsp;
                    <button type="submit"
                        style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-executetryout-POSTapi-v1-auth-logout" data-initial-text="Send Request 💥"
                        data-loading-text="⏱ Sending..." hidden>Send Request 💥
                    </button>
                </h3>
                <p>
                    <small class="badge badge-black">POST</small>
                    <b><code>api/v1/auth/logout</code></b>
                </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Content-Type"
                        data-endpoint="POSTapi-v1-auth-logout" value="application/json" data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Accept"
                        data-endpoint="POSTapi-v1-auth-logout" value="application/json" data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
            </form>

            <h2 id="authentication-GETapi-v1-auth-me">Get authenticated user</h2>

            <p>
                <small class="badge badge-darkred">requires authentication</small>
            </p>

            <p>Returns the full profile of the currently authenticated user.
                Use this after login to populate the dashboard with user data.</p>

            <span id="example-requests-GETapi-v1-auth-me">
                <blockquote>Example request:</blockquote>


                <div class="bash-example">
                    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/v1/auth/me" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre>
                </div>


                <div class="javascript-example">
                    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/auth/me"
);

const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
};


fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre>
                </div>

            </span>

            <span id="example-responses-GETapi-v1-auth-me">
                <blockquote>
                    <p>Example response (200):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;success&quot;: true,
    &quot;message&quot;: &quot;User retrieved successfully&quot;,
    &quot;data&quot;: {
        &quot;id&quot;: 1,
        &quot;name&quot;: &quot;John Doe&quot;,
        &quot;email&quot;: &quot;john@example.com&quot;,
        &quot;plan&quot;: &quot;free&quot;,
        &quot;credits_balance&quot;: 50,
        &quot;profile_image&quot;: null,
        &quot;email_verified&quot;: false,
        &quot;is_active&quot;: true,
        &quot;created_at&quot;: &quot;2026-03-24T10:00:00.000000Z&quot;
    }
}</code>
 </pre>
                <blockquote>
                    <p>Example response (401):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;message&quot;: &quot;Unauthenticated.&quot;
}</code>
 </pre>
            </span>
            <span id="execution-results-GETapi-v1-auth-me" hidden>
                <blockquote>Received response<span id="execution-response-status-GETapi-v1-auth-me"></span>:
                </blockquote>
                <pre class="json"><code id="execution-response-content-GETapi-v1-auth-me"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
            </span>
            <span id="execution-error-GETapi-v1-auth-me" hidden>
                <blockquote>Request failed with error:</blockquote>
                <pre><code id="execution-error-message-GETapi-v1-auth-me">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
            </span>
            <form id="form-GETapi-v1-auth-me" data-method="GET" data-path="api/v1/auth/me" data-authed="1"
                data-hasfiles="0" data-isarraybody="0" autocomplete="off"
                onsubmit="event.preventDefault(); executeTryOut('GETapi-v1-auth-me', this);">
                <h3>
                    Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                        style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-tryout-GETapi-v1-auth-me" onclick="tryItOut('GETapi-v1-auth-me');">Try it out ⚡
                    </button>
                    <button type="button"
                        style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-canceltryout-GETapi-v1-auth-me" onclick="cancelTryOut('GETapi-v1-auth-me');"
                        hidden>Cancel 🛑
                    </button>&nbsp;&nbsp;
                    <button type="submit"
                        style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-executetryout-GETapi-v1-auth-me" data-initial-text="Send Request 💥"
                        data-loading-text="⏱ Sending..." hidden>Send Request 💥
                    </button>
                </h3>
                <p>
                    <small class="badge badge-green">GET</small>
                    <b><code>api/v1/auth/me</code></b>
                </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Content-Type"
                        data-endpoint="GETapi-v1-auth-me" value="application/json" data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Accept" data-endpoint="GETapi-v1-auth-me"
                        value="application/json" data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
            </form>

            <h1 id="general">General</h1>



            <h2 id="general-GETapi-v1-health">Health Check</h2>

            <p>
            </p>

            <p>Check if the API is online. No authentication required.
                Use this to verify the server is reachable before making other calls.</p>

            <span id="example-requests-GETapi-v1-health">
                <blockquote>Example request:</blockquote>


                <div class="bash-example">
                    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/v1/health" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre>
                </div>


                <div class="javascript-example">
                    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/health"
);

const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
};


fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre>
                </div>

            </span>

            <span id="example-responses-GETapi-v1-health">
                <blockquote>
                    <p>Example response (200):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;success&quot;: true,
    &quot;message&quot;: &quot;Sales-Spy API v1 is live&quot;,
    &quot;data&quot;: {
        &quot;version&quot;: &quot;1.0.0&quot;,
        &quot;environment&quot;: &quot;production&quot;
    }
}</code>
 </pre>
            </span>
            <span id="execution-results-GETapi-v1-health" hidden>
                <blockquote>Received response<span id="execution-response-status-GETapi-v1-health"></span>:
                </blockquote>
                <pre class="json"><code id="execution-response-content-GETapi-v1-health"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
            </span>
            <span id="execution-error-GETapi-v1-health" hidden>
                <blockquote>Request failed with error:</blockquote>
                <pre><code id="execution-error-message-GETapi-v1-health">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
            </span>
            <form id="form-GETapi-v1-health" data-method="GET" data-path="api/v1/health" data-authed="0"
                data-hasfiles="0" data-isarraybody="0" autocomplete="off"
                onsubmit="event.preventDefault(); executeTryOut('GETapi-v1-health', this);">
                <h3>
                    Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                        style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-tryout-GETapi-v1-health" onclick="tryItOut('GETapi-v1-health');">Try it out ⚡
                    </button>
                    <button type="button"
                        style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-canceltryout-GETapi-v1-health" onclick="cancelTryOut('GETapi-v1-health');"
                        hidden>Cancel 🛑
                    </button>&nbsp;&nbsp;
                    <button type="submit"
                        style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-executetryout-GETapi-v1-health" data-initial-text="Send Request 💥"
                        data-loading-text="⏱ Sending..." hidden>Send Request 💥
                    </button>
                </h3>
                <p>
                    <small class="badge badge-green">GET</small>
                    <b><code>api/v1/health</code></b>
                </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Content-Type" data-endpoint="GETapi-v1-health"
                        value="application/json" data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Accept" data-endpoint="GETapi-v1-health"
                        value="application/json" data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
            </form>

            <h1 id="oauth">OAuth</h1>



            <h2 id="oauth-GETapi-v1-auth--provider--redirect">OAuth Redirect</h2>

            <p>
            </p>

            <p>Redirects the user to the Google or GitHub login page.
                Pass <code>google</code> or <code>github</code> as the provider parameter.
                After the user authenticates, they are sent to the callback endpoint.</p>

            <span id="example-requests-GETapi-v1-auth--provider--redirect">
                <blockquote>Example request:</blockquote>


                <div class="bash-example">
                    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/v1/auth/google/redirect" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre>
                </div>


                <div class="javascript-example">
                    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/auth/google/redirect"
);

const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
};


fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre>
                </div>

            </span>

            <span id="example-responses-GETapi-v1-auth--provider--redirect">
                <blockquote>
                    <p>Example response (302, Redirects to provider login page):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{}</code>
 </pre>
                <blockquote>
                    <p>Example response (500):</p>
                </blockquote>
                <details class="annotation">
                    <summary style="cursor: pointer;">
                        <small
                            onclick="textContent = parentElement.parentElement.open ? 'Show headers' : 'Hide headers'">Show
                            headers</small>
                    </summary>
                    <pre><code class="language-http">cache-control: no-cache, private
content-type: application/json
access-control-allow-origin: *
 </code></pre>
                </details>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;message&quot;: &quot;Server Error&quot;
}</code>
 </pre>
            </span>
            <span id="execution-results-GETapi-v1-auth--provider--redirect" hidden>
                <blockquote>Received response<span
                        id="execution-response-status-GETapi-v1-auth--provider--redirect"></span>:
                </blockquote>
                <pre class="json"><code id="execution-response-content-GETapi-v1-auth--provider--redirect"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
            </span>
            <span id="execution-error-GETapi-v1-auth--provider--redirect" hidden>
                <blockquote>Request failed with error:</blockquote>
                <pre><code id="execution-error-message-GETapi-v1-auth--provider--redirect">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
            </span>
            <form id="form-GETapi-v1-auth--provider--redirect" data-method="GET"
                data-path="api/v1/auth/{provider}/redirect" data-authed="0" data-hasfiles="0" data-isarraybody="0"
                autocomplete="off"
                onsubmit="event.preventDefault(); executeTryOut('GETapi-v1-auth--provider--redirect', this);">
                <h3>
                    Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                        style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-tryout-GETapi-v1-auth--provider--redirect"
                        onclick="tryItOut('GETapi-v1-auth--provider--redirect');">Try it out ⚡
                    </button>
                    <button type="button"
                        style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-canceltryout-GETapi-v1-auth--provider--redirect"
                        onclick="cancelTryOut('GETapi-v1-auth--provider--redirect');" hidden>Cancel 🛑
                    </button>&nbsp;&nbsp;
                    <button type="submit"
                        style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-executetryout-GETapi-v1-auth--provider--redirect" data-initial-text="Send Request 💥"
                        data-loading-text="⏱ Sending..." hidden>Send Request 💥
                    </button>
                </h3>
                <p>
                    <small class="badge badge-green">GET</small>
                    <b><code>api/v1/auth/{provider}/redirect</code></b>
                </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Content-Type"
                        data-endpoint="GETapi-v1-auth--provider--redirect" value="application/json"
                        data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Accept"
                        data-endpoint="GETapi-v1-auth--provider--redirect" value="application/json"
                        data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <h4 class="fancy-heading-panel"><b>URL Parameters</b></h4>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>provider</code></b>&nbsp;&nbsp;
                    <small>string</small>&nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="provider"
                        data-endpoint="GETapi-v1-auth--provider--redirect" value="google" data-component="url">
                    <br>
                    <p>The OAuth provider. Accepted: google, github. Example: <code>google</code></p>
                </div>
            </form>

            <h2 id="oauth-GETapi-v1-auth--provider--callback">OAuth Callback</h2>

            <p>
            </p>

            <p>Handles the response from Google or GitHub after the user authenticates.
                Returns a Bearer token exactly like the login endpoint does.
                The frontend should redirect here and extract the token from the response.</p>

            <span id="example-requests-GETapi-v1-auth--provider--callback">
                <blockquote>Example request:</blockquote>


                <div class="bash-example">
                    <pre><code class="language-bash">curl --request GET \
    --get "http://localhost:8000/api/v1/auth/google/callback" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json"</code></pre>
                </div>


                <div class="javascript-example">
                    <pre><code class="language-javascript">const url = new URL(
    "http://localhost:8000/api/v1/auth/google/callback"
);

const headers = {
    "Content-Type": "application/json",
    "Accept": "application/json",
};


fetch(url, {
    method: "GET",
    headers,
}).then(response =&gt; response.json());</code></pre>
                </div>

            </span>

            <span id="example-responses-GETapi-v1-auth--provider--callback">
                <blockquote>
                    <p>Example response (200):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;success&quot;: true,
    &quot;message&quot;: &quot;OAuth login successful&quot;,
    &quot;data&quot;: {
        &quot;token&quot;: &quot;3|xxxxxxxxxxxxxxxxxxxxxxxx&quot;,
        &quot;user&quot;: {
            &quot;id&quot;: 2,
            &quot;name&quot;: &quot;Jane Doe&quot;,
            &quot;email&quot;: &quot;jane@gmail.com&quot;,
            &quot;plan&quot;: &quot;free&quot;,
            &quot;credits_balance&quot;: 50
        }
    }
}</code>
 </pre>
                <blockquote>
                    <p>Example response (400):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;success&quot;: false,
    &quot;message&quot;: &quot;Unsupported OAuth provider&quot;,
    &quot;errors&quot;: null
}</code>
 </pre>
                <blockquote>
                    <p>Example response (500):</p>
                </blockquote>
                <pre>

<code class="language-json" style="max-height: 300px;">{
    &quot;success&quot;: false,
    &quot;message&quot;: &quot;OAuth authentication failed&quot;,
    &quot;errors&quot;: null
}</code>
 </pre>
            </span>
            <span id="execution-results-GETapi-v1-auth--provider--callback" hidden>
                <blockquote>Received response<span
                        id="execution-response-status-GETapi-v1-auth--provider--callback"></span>:
                </blockquote>
                <pre class="json"><code id="execution-response-content-GETapi-v1-auth--provider--callback"
      data-empty-response-text="<Empty response>" style="max-height: 400px;"></code></pre>
            </span>
            <span id="execution-error-GETapi-v1-auth--provider--callback" hidden>
                <blockquote>Request failed with error:</blockquote>
                <pre><code id="execution-error-message-GETapi-v1-auth--provider--callback">

Tip: Check that you&#039;re properly connected to the network.
If you&#039;re a maintainer of ths API, verify that your API is running and you&#039;ve enabled CORS.
You can check the Dev Tools console for debugging information.</code></pre>
            </span>
            <form id="form-GETapi-v1-auth--provider--callback" data-method="GET"
                data-path="api/v1/auth/{provider}/callback" data-authed="0" data-hasfiles="0" data-isarraybody="0"
                autocomplete="off"
                onsubmit="event.preventDefault(); executeTryOut('GETapi-v1-auth--provider--callback', this);">
                <h3>
                    Request&nbsp;&nbsp;&nbsp;
                    <button type="button"
                        style="background-color: #8fbcd4; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-tryout-GETapi-v1-auth--provider--callback"
                        onclick="tryItOut('GETapi-v1-auth--provider--callback');">Try it out ⚡
                    </button>
                    <button type="button"
                        style="background-color: #c97a7e; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-canceltryout-GETapi-v1-auth--provider--callback"
                        onclick="cancelTryOut('GETapi-v1-auth--provider--callback');" hidden>Cancel 🛑
                    </button>&nbsp;&nbsp;
                    <button type="submit"
                        style="background-color: #6ac174; padding: 5px 10px; border-radius: 5px; border-width: thin;"
                        id="btn-executetryout-GETapi-v1-auth--provider--callback" data-initial-text="Send Request 💥"
                        data-loading-text="⏱ Sending..." hidden>Send Request 💥
                    </button>
                </h3>
                <p>
                    <small class="badge badge-green">GET</small>
                    <b><code>api/v1/auth/{provider}/callback</code></b>
                </p>
                <h4 class="fancy-heading-panel"><b>Headers</b></h4>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Content-Type</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Content-Type"
                        data-endpoint="GETapi-v1-auth--provider--callback" value="application/json"
                        data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>Accept</code></b>&nbsp;&nbsp;
                    &nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="Accept"
                        data-endpoint="GETapi-v1-auth--provider--callback" value="application/json"
                        data-component="header">
                    <br>
                    <p>Example: <code>application/json</code></p>
                </div>
                <h4 class="fancy-heading-panel"><b>URL Parameters</b></h4>
                <div style="padding-left: 28px; clear: unset;">
                    <b style="line-height: 2;"><code>provider</code></b>&nbsp;&nbsp;
                    <small>string</small>&nbsp;
                    &nbsp;
                    &nbsp;
                    <input type="text" style="display: none" name="provider"
                        data-endpoint="GETapi-v1-auth--provider--callback" value="google" data-component="url">
                    <br>
                    <p>The OAuth provider. Accepted: google, github. Example: <code>google</code></p>
                </div>
            </form>




        </div>
        <div class="dark-box">
            <div class="lang-selector">
                <button type="button" class="lang-button" data-language-name="bash">bash</button>
                <button type="button" class="lang-button" data-language-name="javascript">javascript</button>
            </div>
        </div>
    </div>
</body>

</html>
