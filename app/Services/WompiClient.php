<?php

namespace App\Services;

use App\Exceptions\WompiUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * The only code that calls the Wompi API. The private key is used here (server side) as a Bearer token, with a timeout and a
 * few retries on network errors and 5xx. A failure raises WompiUnavailableException: callers must never treat "could not ask"
 * as "not paid" (the expiry command, for example, never cancels an order when Wompi did not answer).
 *
 * Source: docs.wompi.co/docs/colombia/transacciones ("la consulta de transacciones únicamente está disponible mediante Llave
 * Privada") and the public Swagger spec (GET /transactions/{id}, GET /transactions?reference=).
 */
class WompiClient
{
    public function __construct(private readonly WompiService $wompi) {}

    /** @return array<string, mixed>|null the transaction (`data`), or null when Wompi does not know that id */
    public function transaction(string $id): ?array
    {
        $response = $this->get('/transactions/'.rawurlencode($id));

        return $response->status() === 404 ? null : $this->data($response->json('data'));
    }

    /**
     * The transactions registered for one of our references (newest first when Wompi says so). The shape of this list is not
     * documented, so both a plain list and a list under `data.transactions` / `data` are accepted.
     *
     * @return list<array<string, mixed>>
     */
    public function transactionsByReference(string $reference): array
    {
        $response = $this->get('/transactions', ['reference' => $reference]);
        if ($response->status() === 404) {
            return [];
        }
        $data = $response->json('data');
        $list = is_array($data) && array_is_list($data) ? $data : (is_array($data) ? ($data['transactions'] ?? (isset($data['id']) ? [$data] : [])) : []);

        return array_values(array_filter($list, fn ($row) => is_array($row) && isset($row['id'])));
    }

    private function get(string $path, array $query = [])
    {
        try {
            $response = $this->http()->get($path, $query);
        } catch (ConnectionException|RequestException $error) {
            throw new WompiUnavailableException('Wompi no respondió.', 0, $error);
        }
        if ($response->serverError() || $response->status() === 429 || in_array($response->status(), [401, 403], true)) {
            // 401/403 means the keys are wrong: also "could not ask", not "the transaction does not exist".
            throw new WompiUnavailableException('Wompi respondió '.$response->status().'.');
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->wompi->baseUrl())->withToken((string) config('services.wompi.private_key'))->acceptJson()
            ->timeout(10)->connectTimeout(5)
            ->retry(2, 300, fn ($exception) => $exception instanceof ConnectionException || ($exception instanceof RequestException && $exception->response->serverError()), throw: false);
    }

    private function data(mixed $data): ?array
    {
        return is_array($data) && isset($data['id']) ? $data : null;
    }
}
