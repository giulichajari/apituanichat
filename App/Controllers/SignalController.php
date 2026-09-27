<?php
namespace App\Controllers;

use App\Models\SignalModel;
use EasyProjects\SimpleRouter\Router;

class SignalController
{
    public function __construct(private ?SignalModel $signalModel = new SignalModel()) {}
    public function addOffer() { $this->handle('offer', true); }
    public function getOffer() { $this->handle('offer', false); }
    public function addAnswer() { $this->handle('answer', true); }
    public function getAnswer() { $this->handle('answer', false); }
    public function addCandidate() { $this->handle('candidate', true); }
    public function getCandidates() { $this->handle('candidate', false); }

    private function handle(string $type, bool $write): void
    {
        $id = Router::$request->user->id ?? null;
        if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id)
            || filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) === false) {
            Router::$response->status(401)->send(['message'=>'Unauthorized']);
            return;
        }
        $input = $write ? (Router::$request->body ?? null) : (Router::$request->params ?? null);
        $session = $input->session_id ?? $input->chatId ?? null;
        // chatId is only an alias for a registered call ID, never authorization by chat membership.
        if (!is_string($session) || !preg_match('/^[a-zA-Z0-9_.:-]{1,120}$/D', $session)) {
            Router::$response->status(400)->send(['message'=>'Invalid session_id']);
            return;
        }
        try {
            $payload = $type === 'candidate' ? ($input->candidate ?? null) : ($input->sdp ?? null);
            $data = $this->signalModel->exchange($session, (int)$id, $type, $write, $payload);
            if ($write) Router::$response->status(201)->send(['message'=>'Signal stored']);
            elseif ($data === null || $data === []) Router::$response->status(404)->send(['message'=>'Signal not found']);
            else Router::$response->status(200)->send(['data'=>$data,'message'=>'Signal retrieved']);
        } catch (\LengthException $e) {
            Router::$response->status(413)->send(['message'=>'Signal too large']);
        } catch (\InvalidArgumentException $e) {
            Router::$response->status(400)->send(['message'=>'Invalid signal']);
        } catch (\DomainException $e) {
            $code = in_array($e->getCode(), [403,404,409,429], true) ? $e->getCode() : 403;
            Router::$response->status($code)->send(['message'=>'Signal unavailable or not authorized']);
        } catch (\Throwable $e) {
            error_log('HTTP signaling operation failed');
            Router::$response->status(500)->send(['message'=>'Signaling unavailable']);
        }
    }
}
