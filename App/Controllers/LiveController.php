<?php

namespace App\Controllers;

use App\Models\GroupPostsModel;
use App\Models\GroupsModel;
use EasyProjects\SimpleRouter\Router;

class LiveController
{
    private const RTMP_BASE = 'rtmp://live.tuanichat.com/live';
    private const HLS_BASE  = 'https://live.tuanichat.com/hls';

    public function __construct(
        private ?GroupPostsModel $groupPostsModel = null,
        private ?GroupsModel $groupsModel = null
    ) {
        $this->groupPostsModel = $groupPostsModel ?? new GroupPostsModel();
        $this->groupsModel = $groupsModel ?? new GroupsModel();
    }

    public function startLive()
    {
        $groupId = (int) (Router::$request->params->idGroup ?? 0);
        $userId  = (int) (Router::$request->user->id ?? 0);

        $body       = Router::$request->body;
        $visibility = $body->visibility ?? 'public';
        $giftAmount = $body->gift_amount ?? null;

        if ($groupId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        if (!in_array($visibility, ['public', 'private'], true)) {
            Router::$response->status(400)->send(["message" => "Invalid visibility"]);
            return;
        }

        if (!$this->groupsModel->isUserInGroup($groupId, $userId)) {
            Router::$response->status(403)->send(["message" => "No perteneces a este grupo"]);
            return;
        }

        $result = $this->groupPostsModel->createLivePost($groupId, $userId, $visibility, $giftAmount);

        if (!$result) {
            Router::$response->status(500)->send(["message" => "Error creando el live"]);
            return;
        }

        Router::$response->status(201)->send([
            "message"      => "Live creado, listo para transmitir",
            "post_id"      => $result['post_id'],
            "rtmp_url"     => self::RTMP_BASE,
            "stream_key"   => $result['stream_key'],
            "playback_url" => self::HLS_BASE . '/' . $result['stream_key'] . '.m3u8',
        ]);
    }

    public function getCurrentLive()
    {
        $groupId = (int) (Router::$request->params->idGroup ?? 0);
        $userId  = (int) (Router::$request->user->id ?? 0);

        if ($groupId <= 0 || $userId <= 0) {
            Router::$response->status(400)->send(["message" => "Invalid request"]);
            return;
        }

        if (!$this->groupsModel->isUserInGroup($groupId, $userId)) {
            Router::$response->status(403)->send(["message" => "No perteneces a este grupo"]);
            return;
        }

        $post = $this->groupPostsModel->getActiveLiveByGroup($groupId);

        if (!$post) {
            Router::$response->status(200)->send(["data" => null, "message" => "No hay live activo"]);
            return;
        }

        Router::$response->status(200)->send([
            "data" => [
                "post_id"      => $post['id'],
                "user_id"      => $post['user_id'],
                "playback_url" => self::HLS_BASE . '/' . $post['stream_key'] . '.m3u8',
                "started_at"   => $post['started_at'],
            ],
            "message" => "Live activo"
        ]);
    }

    public function onPublish()
    {
        if (!$this->isInternalRequest()) {
            Router::$response->status(403)->send(["message" => "Forbidden"]);
            return;
        }

        $streamKey = $_POST['name'] ?? (Router::$request->body->name ?? null);

        if (!$streamKey) {
            Router::$response->status(400)->send(["message" => "Missing stream key"]);
            return;
        }

        $post = $this->groupPostsModel->getPostByStreamKey($streamKey);

        if (!$post || $post['type'] !== 'live') {
            Router::$response->status(403)->send(["message" => "Invalid stream key"]);
            return;
        }

        if ($post['status'] === 'ended') {
            Router::$response->status(403)->send(["message" => "This live has already ended"]);
            return;
        }

        $this->groupPostsModel->markLiveStarted($streamKey);

        Router::$response->status(200)->send(["message" => "OK"]);
    }

    public function onPublishDone()
    {
        if (!$this->isInternalRequest()) {
            Router::$response->status(403)->send(["message" => "Forbidden"]);
            return;
        }

        $streamKey = $_POST['name'] ?? (Router::$request->body->name ?? null);

        if (!$streamKey) {
            Router::$response->status(400)->send(["message" => "Missing stream key"]);
            return;
        }

        $this->groupPostsModel->markLiveEnded($streamKey);

        Router::$response->status(200)->send(["message" => "OK"]);
    }

    private function isInternalRequest(): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($ip, ['127.0.0.1', '::1'], true);
    }
}
