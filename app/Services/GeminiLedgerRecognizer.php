<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiLedgerRecognizer
{
    private const MAX_ATTEMPTS = 3;

    public function recognize(UploadedFile $image): array
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            throw new RuntimeException('Gemini ledger recognition is not configured. Set GEMINI_API_KEY on the server.');
        }

        $model = config('services.gemini.model', 'gemini-3.8-flash');
        $requestPayload = [
            'model' => $model,
            'input' => [
                ['type' => 'text', 'text' => $this->prompt()],
                [
                    'type' => 'image',
                    'data' => base64_encode($image->get()),
                    'mime_type' => $image->getMimeType(),
                ],
            ],
            'response_format' => [
                'type' => 'text',
                'mime_type' => 'application/json',
                'schema' => $this->responseSchema(),
            ],
            'generation_config' => [
                'thinking_level' => 'low',
            ],
            'store' => false,
        ];

        $response = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->timeout(180)
                    ->post('https://generativelanguage.googleapis.com/v1beta/interactions', $requestPayload);
            } catch (ConnectionException $exception) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep($this->retryDelayMilliseconds() * 1000);
                    continue;
                }

                Log::warning('Gemini ledger recognition could not connect.', [
                    'message' => $exception->getMessage(),
                    'attempts' => $attempt,
                ]);
                throw new RuntimeException('Could not connect to Gemini to read the ledger.', 0, $exception);
            }

            if (!$this->isTemporaryFailure($response) || $attempt === self::MAX_ATTEMPTS) {
                break;
            }

            usleep($this->retryDelayMilliseconds($response) * 1000);
        }

        if (!$response) {
            throw new RuntimeException('Gemini could not read the ledger image. Please try again.');
        }

        if (!$response->successful()) {
            $providerMessage = data_get($response->json(), 'error.message');
            Log::warning('Gemini ledger recognition request failed.', [
                'status' => $response->status(),
                'model' => $model,
                'attempts' => $attempt,
                'provider_message' => is_string($providerMessage) ? $providerMessage : null,
            ]);

            if ($this->isTemporaryFailure($response)) {
                throw new RuntimeException(
                    'Gemini is temporarily busy. Your ledger image is safe; please try again in a moment.',
                    503
                );
            }

            if (is_string($providerMessage) && $providerMessage !== '') {
                throw new RuntimeException("Gemini request failed: {$providerMessage}");
            }

            if ($response->status() === 404) {
                throw new RuntimeException(
                    "Gemini could not find model \"{$model}\" for this API key. Check GEMINI_MODEL in .env, then run php artisan config:clear."
                );
            }

            throw new RuntimeException('Gemini could not read the ledger image. Please try again.');
        }

        $responsePayload = $response->json();
        $interaction = data_get($responsePayload, 'interaction', $responsePayload);
        $text = data_get($interaction, 'output_text');
        if (!is_string($text)) {
            $text = $this->extractOutputText(
                data_get($interaction, 'output', data_get($interaction, 'steps', []))
            );
        }
        $payload = is_string($text) ? json_decode($text, true) : null;
        if (!is_array($payload) || !is_array($payload['records'] ?? null)) {
            Log::warning('Gemini ledger recognition returned no valid structured text.', [
                'response_keys' => is_array($responsePayload) ? array_keys($responsePayload) : [],
                'interaction_keys' => is_array($interaction) ? array_keys($interaction) : [],
                'output_types' => array_values(array_filter(array_map(
                    static fn ($item) => is_array($item) ? ($item['type'] ?? null) : null,
                    data_get($interaction, 'output', data_get($interaction, 'steps', []))
                ))),
                'json_error' => is_string($text) ? json_last_error_msg() : 'No text output found.',
            ]);
            throw new RuntimeException('Gemini returned an unreadable response. Please try again.');
        }

        return $this->normalizeRecords($payload['records']);
    }

    private function isTemporaryFailure(\Illuminate\Http\Client\Response $response): bool
    {
        if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
            return true;
        }

        $message = data_get($response->json(), 'error.message');
        return is_string($message) && stripos($message, 'high demand') !== false;
    }

    private function retryDelayMilliseconds(?\Illuminate\Http\Client\Response $response = null): int
    {
        $retryAfter = $response?->header('Retry-After');
        if (is_string($retryAfter) && is_numeric($retryAfter)) {
            return (int) (min(2, max(0, (float) $retryAfter)) * 1000);
        }

        return $response ? 900 : 400;
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
Read the entire photographed payment ledger and transcribe every visible payment row in top-to-bottom order. For each row, read only the payment date, OR number, and amount columns. Ignore headings, row indexes, other date columns, and blank rows.

Read each row across the page before moving to the next row; align values using the printed horizontal rules. Dates may be handwritten as month/day/two-digit-year (for example, 5/1/26). Use neighboring dates to resolve an unclear date digit only when the sequence makes the reading unambiguous. Repeated neighboring amounts may help interpret handwriting, but never fill a value based only on a pattern. Do not combine the printed row index with the OR number, and do not read the separate right-hand date column as the payment date.

Convert dates to YYYY-MM-DD. Return OR numbers as strings, preserving all visible digits. Return amounts as decimal strings with a dot (for example, "150.00"). Include every visible payment row in order, including partial rows. If a field is unreadable or ambiguous, return an empty string for that field and include it in uncertain_fields. Never guess a missing value or add rows for headings or image artifacts. Return JSON only, matching the requested schema.
PROMPT;
    }

    private function extractOutputText(array $output): string
    {
        $textParts = [];
        $pending = [$output];
        while ($pending !== []) {
            $item = array_pop($pending);
            if (!is_array($item)) {
                continue;
            }

            if (array_is_list($item)) {
                foreach ($item as $child) {
                    if (is_array($child)) {
                        $pending[] = $child;
                    }
                }
                continue;
            }

            if (in_array($item['type'] ?? null, ['text', 'output_text'], true) && is_string($item['text'] ?? null)) {
                $textParts[] = $item['text'];
            }

            foreach (['content', 'parts', 'steps', 'output'] as $childKey) {
                if (is_array($item[$childKey] ?? null)) {
                    foreach ($item[$childKey] as $child) {
                        if (is_array($child)) {
                            $pending[] = $child;
                        }
                    }
                }
            }
        }

        return implode("\n", array_reverse($textParts));
    }

    private function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'records' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'payment_date' => ['type' => 'STRING'],
                            'or_number' => ['type' => 'STRING'],
                            'amount' => ['type' => 'STRING'],
                            'uncertain_fields' => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'STRING',
                                    'enum' => ['payment_date', 'or_number', 'amount'],
                                ],
                            ],
                        ],
                        'required' => ['payment_date', 'or_number', 'amount', 'uncertain_fields'],
                        'propertyOrdering' => ['payment_date', 'or_number', 'amount', 'uncertain_fields'],
                    ],
                ],
            ],
            'required' => ['records'],
            'propertyOrdering' => ['records'],
        ];
    }

    private function normalizeRecords(array $records): array
    {
        $normalized = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }

            $date = trim((string) ($record['payment_date'] ?? ''));
            $orNumber = preg_replace('/\s+/', '', trim((string) ($record['or_number'] ?? '')));
            $amountText = trim((string) ($record['amount'] ?? ''));
            $uncertainFields = array_values(array_intersect(
                ['payment_date', 'or_number', 'amount'],
                is_array($record['uncertain_fields'] ?? null) ? $record['uncertain_fields'] : []
            ));

            if (!$this->isIsoDate($date)) {
                $date = '';
                $uncertainFields[] = 'payment_date';
            }
            if ($orNumber === '') {
                $uncertainFields[] = 'or_number';
            }

            $amount = is_numeric($amountText) ? (float) $amountText : 0;
            if ($amount <= 0) {
                $amount = 0;
                $uncertainFields[] = 'amount';
            }

            if ($date === '' && $orNumber === '' && $amount === 0) {
                continue;
            }

            $uncertainFields = array_values(array_unique($uncertainFields));
            $normalized[] = [
                'payment_date' => $date,
                'or_number' => $orNumber,
                'amount' => $amount,
                'uncertain_fields' => $uncertainFields,
            ];
        }

        return $normalized;
    }

    private function isIsoDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
