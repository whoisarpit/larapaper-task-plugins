<?php

declare(strict_types=1);

namespace GoogleTasksConnector;

use RuntimeException;

load_environment_file();

function repo_root(): string
{
    return dirname(__DIR__);
}

function storage_dir(): string
{
    return repo_root() . "/storage";
}

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);

    if ($value === false || $value === "") {
        return $default;
    }

    return $value;
}

function load_environment_file(): void
{
    $path = repo_root() . "/.env";
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (
            $trimmed === "" ||
            str_starts_with($trimmed, "#") ||
            !str_contains($trimmed, "=")
        ) {
            continue;
        }

        [$name, $value] = explode("=", $trimmed, 2);
        $name = trim($name);
        $value = trim($value);

        if ($name === "" || getenv($name) !== false) {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($name . "=" . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function require_config(): void
{
    foreach (
        ["GOOGLE_CLIENT_ID", "GOOGLE_CLIENT_SECRET", "GOOGLE_REDIRECT_URI"]
        as $key
    ) {
        if (env($key) === null) {
            throw new RuntimeException(
                "Missing required environment variable: {$key}",
            );
        }
    }
}

function base_url(): string
{
    $configured = env("APP_BASE_URL");
    if ($configured) {
        return rtrim($configured, "/");
    }

    $scheme =
        !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"
            ? "https"
            : "http";
    $host = $_SERVER["HTTP_HOST"] ?? "localhost:8080";

    return $scheme . "://" . $host;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit();
}

function redirect(string $location): never
{
    header("Location: " . $location);
    exit();
}

function token_storage_path(): string
{
    return repo_root() .
        "/" .
        ltrim(
            env("GOOGLE_TOKEN_STORAGE", "storage/google_tokens.json") ??
                "storage/google_tokens.json",
            "/",
        );
}

function current_token_store(): ?array
{
    $path = token_storage_path();
    if (!is_file($path)) {
        return null;
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function write_token_store(array $token): void
{
    $path = token_storage_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }

    chmod($dir, 0700);
    touch($path);
    chmod($path, 0600);
    file_put_contents(
        $path,
        json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );
}

function clear_token_store(): void
{
    $path = token_storage_path();
    if (is_file($path)) {
        unlink($path);
    }
}

function build_authorization_url(string $state): string
{
    $query = http_build_query([
        "client_id" => env("GOOGLE_CLIENT_ID"),
        "redirect_uri" => env("GOOGLE_REDIRECT_URI"),
        "response_type" => "code",
        "scope" => "https://www.googleapis.com/auth/tasks.readonly",
        "access_type" => "offline",
        "prompt" => "consent",
        "include_granted_scopes" => "true",
        "state" => $state,
    ]);

    return "https://accounts.google.com/o/oauth2/v2/auth?" . $query;
}

function finish_oauth_callback(array $query, ?string $expectedState): void
{
    if (($query["state"] ?? null) !== $expectedState) {
        throw new RuntimeException("OAuth state mismatch.");
    }

    if (!isset($query["code"]) || $query["code"] === "") {
        throw new RuntimeException("Missing authorization code.");
    }

    $token = exchange_code_for_token((string) $query["code"]);
    write_token_store($token);
}

function exchange_code_for_token(string $code): array
{
    return http_post_form("https://oauth2.googleapis.com/token", [
        "code" => $code,
        "client_id" => env("GOOGLE_CLIENT_ID"),
        "client_secret" => env("GOOGLE_CLIENT_SECRET"),
        "redirect_uri" => env("GOOGLE_REDIRECT_URI"),
        "grant_type" => "authorization_code",
    ]);
}

function refresh_access_token(string $refreshToken): array
{
    return http_post_form("https://oauth2.googleapis.com/token", [
        "refresh_token" => $refreshToken,
        "client_id" => env("GOOGLE_CLIENT_ID"),
        "client_secret" => env("GOOGLE_CLIENT_SECRET"),
        "grant_type" => "refresh_token",
    ]);
}

function get_access_token(): string
{
    $token = current_token_store();
    if (!$token) {
        throw new RuntimeException(
            "No Google token stored. Open /connect first.",
        );
    }

    $expiresAt = (int) ($token["expires_at"] ?? 0);
    if (($token["access_token"] ?? "") !== "" && $expiresAt > time() + 60) {
        return (string) $token["access_token"];
    }

    if (($token["refresh_token"] ?? "") === "") {
        throw new RuntimeException(
            "No refresh token stored. Reconnect with prompt=consent.",
        );
    }

    $refreshed = refresh_access_token((string) $token["refresh_token"]);
    $token["access_token"] = $refreshed["access_token"] ?? null;
    $token["expires_in"] = $refreshed["expires_in"] ?? null;
    $token["expires_at"] = time() + (int) ($refreshed["expires_in"] ?? 0);

    if (isset($refreshed["scope"])) {
        $token["scope"] = $refreshed["scope"];
    }

    write_token_store($token);

    return (string) ($token["access_token"] ?? "");
}

function google_get(string $url, array $query = []): array
{
    $accessToken = get_access_token();

    if ($query !== []) {
        $url .=
            (str_contains($url, "?") ? "&" : "?") . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $accessToken,
            "Accept: application/json",
        ],
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Google API request failed: " . $error);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Google API response was not JSON.");
    }

    if ($status >= 400) {
        throw new RuntimeException(
            "Google API error: " . ($decoded["error"]["message"] ?? $body),
        );
    }

    return $decoded;
}

function http_post_form(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Accept: application/json"],
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);

    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Token request failed: " . $error);
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Token response was not JSON.");
    }

    if ($status >= 400) {
        throw new RuntimeException(
            "Token request failed: " .
                ($decoded["error_description"] ?? ($decoded["error"] ?? $body)),
        );
    }

    if (isset($decoded["expires_in"])) {
        $decoded["expires_at"] = time() + (int) $decoded["expires_in"];
    }

    return $decoded;
}

function default_list_id(): string
{
    return (string) env("GOOGLE_TASKS_LIST_ID", "");
}

function fetch_tasklists(): array
{
    $payload = google_get(
        rtrim(
            env(
                "GOOGLE_TASKS_BASE_URL",
                "https://tasks.googleapis.com/tasks/v1",
            ) ?? "https://tasks.googleapis.com/tasks/v1",
            "/",
        ) . "/users/@me/lists",
        [
            "maxResults" => 1000,
        ],
    );

    $items = [];
    foreach ($payload["items"] ?? [] as $item) {
        $items[] = [
            "id" => $item["id"] ?? null,
            "title" => $item["title"] ?? null,
            "updated" => $item["updated"] ?? null,
            "selfLink" => $item["selfLink"] ?? null,
        ];
    }

    return $items;
}

function fetch_tasks(?string $tasklistId): array
{
    $tasklists = fetch_tasklists();

    if ($tasklistId === null || $tasklistId === "") {
        $tasklistId = (string) ($tasklists[0]["id"] ?? "");
    }

    if ($tasklistId === "") {
        throw new RuntimeException("No task list available.");
    }

    $taskListTitle = null;
    foreach ($tasklists as $tasklist) {
        if (($tasklist["id"] ?? null) === $tasklistId) {
            $taskListTitle = $tasklist["title"] ?? null;
            break;
        }
    }

    $tasksUrl =
        rtrim(
            env(
                "GOOGLE_TASKS_BASE_URL",
                "https://tasks.googleapis.com/tasks/v1",
            ) ?? "https://tasks.googleapis.com/tasks/v1",
            "/",
        ) .
        "/lists/" .
        rawurlencode($tasklistId) .
        "/tasks";
    $taskQuery = [
        "maxResults" => min(
            100,
            max(1, (int) env("GOOGLE_DEFAULT_PAGE_SIZE", "100")),
        ),
        "showCompleted" => filter_var(
            env("GOOGLE_TASKS_SHOW_COMPLETED", "false"),
            FILTER_VALIDATE_BOOL,
        ),
        "showHidden" => filter_var(
            env("GOOGLE_TASKS_SHOW_HIDDEN", "true"),
            FILTER_VALIDATE_BOOL,
        ),
        "showDeleted" => false,
        "showAssigned" => true,
    ];

    $payload = ["items" => []];
    $pageToken = null;
    do {
        $page = google_get($tasksUrl, [
            ...$taskQuery,
            ...$pageToken ? ["pageToken" => $pageToken] : [],
        ]);
        $payload["items"] = [...$payload["items"], ...$page["items"] ?? []];
        $payload["title"] ??= $page["title"] ?? null;
        $payload["updated"] ??= $page["updated"] ?? null;
        $pageToken = $page["nextPageToken"] ?? null;
    } while ($pageToken !== null);

    $tasks = [];
    foreach ($payload["items"] ?? [] as $item) {
        $tasks[] = [
            "id" => $item["id"] ?? null,
            "title" => $item["title"] ?? "Untitled task",
            "notes" => $item["notes"] ?? null,
            "status" => $item["status"] ?? null,
            "updated" => $item["updated"] ?? null,
            "due" => $item["due"] ?? null,
            "completed" => $item["completed"] ?? null,
            "hidden" => (bool) ($item["hidden"] ?? false),
            "deleted" => (bool) ($item["deleted"] ?? false),
            "parent" => $item["parent"] ?? null,
        ];
    }

    $displayTasks = arrange_tasks_for_display($tasks);
    $recentCompletedCount = count(
        array_filter(
            $tasks,
            fn(array $task): bool => is_recently_completed($task),
        ),
    );

    return [
        "generated_at" => gmdate(DATE_ATOM),
        "task_list" => [
            "id" => $tasklistId,
            "title" => $taskListTitle ?? ($payload["title"] ?? $tasklistId),
            "updated" => $payload["updated"] ?? null,
        ],
        "tasks" => $tasks,
        "display_tasks" => $displayTasks,
        "stats" => [
            "open" => count(
                array_filter(
                    $tasks,
                    fn(array $task): bool => $task["status"] !== "completed",
                ),
            ),
            "completed" => count(
                array_filter(
                    $tasks,
                    fn(array $task): bool => $task["status"] === "completed",
                ),
            ),
            "recent_completed" => $recentCompletedCount,
        ],
    ];
}

function arrange_tasks_for_display(array $tasks, ?int $now = null): array
{
    $now ??= time();
    $tasksById = [];
    $childrenByParent = [];

    foreach ($tasks as $task) {
        $id = (string) ($task["id"] ?? "");
        if ($id === "") {
            continue;
        }

        $tasksById[$id] = $task;
        $parent = (string) ($task["parent"] ?? "");
        if ($parent !== "") {
            $childrenByParent[$parent][] = $id;
        }
    }

    $rootIds = [];
    foreach ($tasksById as $id => $task) {
        $parent = (string) ($task["parent"] ?? "");
        if ($parent === "" || !isset($tasksById[$parent])) {
            $rootIds[] = $id;
        }
    }

    $recentCompletedIds = [];
    foreach ($tasksById as $id => $task) {
        if (is_recently_completed($task, $now)) {
            $recentCompletedIds[$id] = true;
        }
    }

    $recentCompletedTaskIds = array_keys($recentCompletedIds);
    usort(
        $recentCompletedTaskIds,
        fn(string $left, string $right): int => completed_timestamp(
            $tasksById[$right],
        ) <=> completed_timestamp($tasksById[$left]),
    );

    $recentRows = array_map(
        fn(string $id): array => display_task_row($tasksById[$id], 0, "recent"),
        $recentCompletedTaskIds,
    );
    $openRows = [];
    $completedRows = [];

    foreach ($rootIds as $rootId) {
        if (($tasksById[$rootId]["status"] ?? null) === "completed") {
            if (isset($recentCompletedIds[$rootId])) {
                continue;
            }

            $completedRows[] = display_task_row(
                $tasksById[$rootId],
                0,
                "completed",
                count_completed_descendants(
                    $rootId,
                    $tasksById,
                    $childrenByParent,
                    $recentCompletedIds,
                ),
            );

            continue;
        }

        append_open_task_branch(
            $rootId,
            0,
            $tasksById,
            $childrenByParent,
            $recentCompletedIds,
            $openRows,
        );
    }

    return [...$recentRows, ...$openRows, ...$completedRows];
}

function is_recently_completed(array $task, ?int $now = null): bool
{
    if (($task["status"] ?? null) !== "completed") {
        return false;
    }

    $now ??= time();
    $completedAt = completed_timestamp($task);

    return $completedAt !== null &&
        $completedAt <= $now &&
        $completedAt >= $now - 86400;
}

function completed_timestamp(array $task): ?int
{
    $completed = $task["completed"] ?? null;
    if (!is_string($completed) || $completed === "") {
        return null;
    }

    $timestamp = strtotime($completed);

    return $timestamp === false ? null : $timestamp;
}

function append_open_task_branch(
    string $taskId,
    int $depth,
    array $tasksById,
    array $childrenByParent,
    array $excludedCompletedIds,
    array &$rows,
): void {
    $task = $tasksById[$taskId];
    $rows[] = display_task_row($task, $depth, "open");

    $completedCount = 0;
    foreach ($childrenByParent[$taskId] ?? [] as $childId) {
        $child = $tasksById[$childId];
        if (($child["status"] ?? null) === "completed") {
            if (!isset($excludedCompletedIds[$childId])) {
                $completedCount++;
            }
            $completedCount += count_completed_descendants(
                $childId,
                $tasksById,
                $childrenByParent,
                $excludedCompletedIds,
            );

            continue;
        }

        append_open_task_branch(
            $childId,
            $depth + 1,
            $tasksById,
            $childrenByParent,
            $excludedCompletedIds,
            $rows,
        );
    }

    if ($completedCount > 0) {
        $rows[] = [
            "kind" => "completed_summary",
            "title" => "({$completedCount} completed)",
            "completed_count" => $completedCount,
            "depth" => $depth + 1,
            "status" => "completed",
        ];
    }
}

function display_task_row(
    array $task,
    int $depth,
    string $section,
    int $completedDescendants = 0,
): array {
    return [
        ...$task,
        "kind" => "task",
        "depth" => $depth,
        "section" => $section,
        "completed_descendants" => $completedDescendants,
    ];
}

function count_completed_descendants(
    string $taskId,
    array $tasksById,
    array $childrenByParent,
    array $excludedCompletedIds = [],
): int {
    $count = 0;
    foreach ($childrenByParent[$taskId] ?? [] as $childId) {
        if (
            ($tasksById[$childId]["status"] ?? null) === "completed" &&
            !isset($excludedCompletedIds[$childId])
        ) {
            $count++;
        }

        $count += count_completed_descendants(
            $childId,
            $tasksById,
            $childrenByParent,
            $excludedCompletedIds,
        );
    }

    return $count;
}

function render_homepage(array $state = []): void
{
    $token = $state["token"] ?? null;
    $connected = (bool) ($state["connected"] ?? false);
    $disconnected = (bool) ($state["disconnected"] ?? false);
    $feedUrl = (string) ($state["feed_url"] ?? "");
    $listId = default_list_id();
    $tokenMessage = $token ? "Token store present" : "No token stored yet";
    $authUrl = "/connect";

    header("Content-Type: text/html; charset=utf-8");
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo "<title>LaraPaper Google Tasks Connector</title>";
    echo '<style>
        body{font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:linear-gradient(135deg,#f3efe5,#e8f0ff);color:#132238;margin:0;padding:40px}
        .card{max-width:860px;margin:0 auto;background:rgba(255,255,255,.86);backdrop-filter:blur(10px);border:1px solid rgba(19,34,56,.1);border-radius:24px;padding:32px;box-shadow:0 20px 60px rgba(19,34,56,.12)}
        code,pre{background:#0f172a;color:#e2e8f0;border-radius:12px;padding:2px 8px}
        a.button{display:inline-block;background:#132238;color:white;padding:12px 16px;border-radius:12px;text-decoration:none;font-weight:600}
        .row{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
        .muted{color:#516072}
    </style></head><body><div class="card">';
    echo "<h1>LaraPaper Google Tasks Connector</h1>";
    echo '<p class="muted">Read-only Google Tasks feed with OAuth-backed refresh tokens.</p>';
    if ($connected) {
        echo "<p><strong>Connected.</strong> Refresh token stored locally.</p>";
    }
    if ($disconnected) {
        echo "<p><strong>Disconnected.</strong> Token store cleared.</p>";
    }
    echo "<p>" . $tokenMessage . "</p>";
    echo '<div class="row">';
    echo '<a class="button" href="' .
        htmlspecialchars($authUrl, ENT_QUOTES) .
        '">Connect Google Tasks</a>';
    echo '<a class="button" href="/tasks">View Feed</a>';
    echo '<a class="button" href="/lists">Inspect Lists</a>';
    echo "</div>";
    echo "<h2>Feed URL</h2><p><code>" .
        htmlspecialchars($feedUrl, ENT_QUOTES) .
        "</code></p>";
    echo "<h2>Config</h2><ul>";
    echo "<li>Task list: <code>" .
        htmlspecialchars($listId ?: "(default first list)", ENT_QUOTES) .
        "</code></li>";
    echo "<li>Stored token: <code>" .
        htmlspecialchars($tokenMessage, ENT_QUOTES) .
        "</code></li>";
    echo "</ul>";
    echo '<form method="post" action="/disconnect" onsubmit="return confirm(\'Clear stored token?\')">';
    echo '<button class="button" type="submit" style="border:none;cursor:pointer;margin-top:12px">Disconnect</button>';
    echo "</form>";
    echo "</div></body></html>";
}
