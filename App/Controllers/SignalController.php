<?php

namespace App\Controllers;

use App\Models\SignalModel;
use EasyProjects\SimpleRouter\Router;

class SignalController
{
    public function __construct(
        private ?SignalModel $signalModel = new SignalModel(),
    ) {}

    // Guardar una oferta (User A inicia llamada)
    public function addOffer()
    {

        $sessionId = Router::$request->body->session_id ?? Router::$request->body->chatId;
        $payload = Router::$request->body->sdp ?? null;
        error_log("addOffer | sessionId: " . $sessionId);
        error_log("addOffer | payload: " . json_encode($payload));

        if (!$sessionId || !$payload) {
            Router::$response->status(400)->send(["message" => "Missing session_id or sdp"]);
            return;
        }

        // Convertir stdClass a array
        if (is_object($payload)) {
            $payload = json_decode(json_encode($payload), true);
        }

        if ($this->signalModel->addSignal($sessionId, 'offer', $payload)) {
            Router::$response->status(201)->send(["message" => "Offer stored"]);
        } else {
            Router::$response->status(500)->send(["message" => "Error storing offer"]);
        }
    }

    // Obtener oferta (User B recibe)
    public function getOffer()
    {
        $sessionId = Router::$request->params->session_id ?? Router::$request->params->chatId;

        if (!$sessionId) {
            Router::$response->status(400)->send(["message" => "Missing session_id"]);
            return;
        }

        $offer = $this->signalModel->getSignal($sessionId, 'offer');

        if ($offer) {
            Router::$response->status(200)->send([
                "data" => $offer,
                "message" => "Offer retrieved"
            ]);
        } else {
            Router::$response->status(404)->send(["message" => "Offer not found"]);
        }
    }

    // Guardar una respuesta (User B responde)
    public function addAnswer()
    {
        $sessionId = Router::$request->body->session_id ?? Router::$request->body->chatId;
        $payload = Router::$request->body->sdp ?? null;

        if (!$sessionId || !$payload) {
            Router::$response->status(400)->send(["message" => "Missing session_id or sdp"]);
            return;
        }

        if (is_object($payload)) {
            $payload = json_decode(json_encode($payload), true);
        }

        if ($this->signalModel->addSignal($sessionId, 'answer', $payload)) {
            Router::$response->status(201)->send(["message" => "Answer stored"]);
        } else {
            Router::$response->status(500)->send(["message" => "Error storing answer"]);
        }
    }

    // Obtener respuesta (User A recibe)
    public function getAnswer()
    {
        $sessionId = Router::$request->params->session_id ?? Router::$request->params->chatId;

        if (!$sessionId) {
            Router::$response->status(400)->send(["message" => "Missing session_id"]);
            return;
        }

        $answer = $this->signalModel->getSignal($sessionId, 'answer');

        if ($answer) {
            Router::$response->status(200)->send([
                "data" => $answer,
                "message" => "Answer retrieved"
            ]);
        } else {
            Router::$response->status(404)->send(["message" => "Answer not found"]);
        }
    }

    // Guardar un candidato ICE
    public function addCandidate()
    {
        $sessionId = Router::$request->body->session_id ?? Router::$request->body->chatId;
        $payload = Router::$request->body->candidate ?? null;

        if (!$sessionId || !$payload) {
            Router::$response->status(400)->send(["message" => "Missing session_id or candidate"]);
            return;
        }

        if (is_object($payload)) {
            $payload = json_decode(json_encode($payload), true);
        }

        if ($this->signalModel->addSignal($sessionId, 'candidate', $payload)) {
            Router::$response->status(201)->send(["message" => "Candidate stored"]);
        } else {
            Router::$response->status(500)->send(["message" => "Error storing candidate"]);
        }
    }

    // Obtener candidatos ICE
    public function getCandidates()
    {
        $sessionId = Router::$request->params->session_id ?? Router::$request->params->chatId;

        if (!$sessionId) {
            Router::$response->status(400)->send(["message" => "Missing session_id"]);
            return;
        }

        $candidates = $this->signalModel->getCandidates($sessionId);

        if ($candidates) {
            Router::$response->status(200)->send([
                "data" => $candidates,
                "message" => "Candidates retrieved"
            ]);
        } else {
            Router::$response->status(404)->send(["message" => "Candidates not found"]);
        }
    }
}
