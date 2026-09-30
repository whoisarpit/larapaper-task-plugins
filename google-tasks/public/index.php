<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . "/../src/google_tasks.php";

use function GoogleTasksConnector\base_url;
use function GoogleTasksConnector\build_authorization_url;
use function GoogleTasksConnector\clear_token_store;
use function GoogleTasksConnector\current_token_store;
use function GoogleTasksConnector\default_list_id;
use function GoogleTasksConnector\fetch_tasklists;
use function GoogleTasksConnector\fetch_tasks;
use function GoogleTasksConnector\finish_oauth_callback;
use function GoogleTasksConnector\json_response;
use function GoogleTasksConnector\render_homepage;
use function GoogleTasksConnector\require_config;

$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";

if ($path === "/health") {
    json_response(["ok" => true]);
}

if ($path === "/connect") {
    require_config();

    $state = bin2hex(random_bytes(16));
    $_SESSION["google_tasks_state"] = $state;

    header("Location: " . build_authorization_url($state));
    exit();
}

if ($path === "/callback") {
    require_config();

    try {
        finish_oauth_callback($_GET, $_SESSION["google_tasks_state"] ?? null);

        header("Location: /?connected=1");
        exit();
    } catch (Throwable $exception) {
        http_response_code(500);
        header("Content-Type: text/html; charset=utf-8");
        echo "<h1>OAuth callback failed</h1>";
        echo "<pre>" .
            htmlspecialchars($exception->getMessage(), ENT_QUOTES) .
            "</pre>";
        exit();
    }
}

if (
    $path === "/disconnect" &&
    strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST"
) {
    clear_token_store();
    unset($_SESSION["google_tasks_state"]);

    header("Location: /?disconnected=1");
    exit();
}

if ($path === "/lists") {
    require_config();
    json_response([
        "base_url" => base_url(),
        "tasklists" => fetch_tasklists(),
    ]);
}

if ($path === "/tasks") {
    require_config();

    $listId = (string) ($_GET["list"] ?? default_list_id());
    $payload = fetch_tasks($listId !== "" ? $listId : null);

    json_response($payload);
}

render_homepage([
    "connected" => isset($_GET["connected"]),
    "disconnected" => isset($_GET["disconnected"]),
    "token" => current_token_store(),
    "feed_url" => rtrim(base_url(), "/") . "/tasks",
]);
