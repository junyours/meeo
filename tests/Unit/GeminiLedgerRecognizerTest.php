<?php

namespace Tests\Unit;

use App\Services\GeminiLedgerRecognizer;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GeminiLedgerRecognizerTest extends TestCase
{
    public function test_it_sends_the_image_to_gemini_and_returns_normalized_rows(): void
    {
        config([
            'services.gemini.api_key' => 'test-api-key',
            'services.gemini.model' => 'gemini-3.8-flash',
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'steps' => [[
                    'type' => 'model_output',
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode([
                                'records' => [[
                                    'payment_date' => '2026-05-01',
                                    'or_number' => '4690914',
                                    'amount' => '150.00',
                                    'uncertain_fields' => [],
                                ], [
                                    'payment_date' => '',
                                    'or_number' => '4691534',
                                    'amount' => '',
                                    'uncertain_fields' => ['payment_date', 'amount'],
                                ]],
                            ]),
                    ]],
                ]],
            ]),
        ]);

        $image = UploadedFile::fake()->create('ledger.jpg', 10, 'image/jpeg');
        $records = app(GeminiLedgerRecognizer::class)->recognize($image);

        $this->assertSame([
            [
                'payment_date' => '2026-05-01',
                'or_number' => '4690914',
                'amount' => 150.0,
                'uncertain_fields' => [],
            ],
            [
                'payment_date' => '',
                'or_number' => '4691534',
                'amount' => 0,
                'uncertain_fields' => ['payment_date', 'amount'],
            ],
        ], $records);

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/v1beta/interactions')
                && $request->hasHeader('x-goog-api-key', 'test-api-key')
                && $request['model'] === 'gemini-3.8-flash'
                && isset($request['input'][1]['data'])
                && $request['input'][1]['mime_type'] === 'image/jpeg'
                && $request['response_format']['mime_type'] === 'application/json'
                && $request['generation_config']['thinking_level'] === 'low'
                && $request['store'] === false;
        });
    }

    public function test_it_marks_invalid_dates_as_uncertain_and_skips_blank_rows(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'output_text' => json_encode([
                                'records' => [[
                                    'payment_date' => '2026-02-30',
                                    'or_number' => '1234',
                                    'amount' => '150.00',
                                    'uncertain_fields' => [],
                                ], [
                                    'payment_date' => '',
                                    'or_number' => '',
                                    'amount' => '',
                                    'uncertain_fields' => [],
                                ]],
                            ]),
            ]),
        ]);

        $records = app(GeminiLedgerRecognizer::class)->recognize(
            UploadedFile::fake()->create('ledger.jpg', 10, 'image/jpeg')
        );

        $this->assertSame([[
            'payment_date' => '',
            'or_number' => '1234',
            'amount' => 150.0,
            'uncertain_fields' => ['payment_date'],
        ]], $records);
    }

    public function test_it_requires_a_gemini_api_key(): void
    {
        config(['services.gemini.api_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Set GEMINI_API_KEY');

        app(GeminiLedgerRecognizer::class)->recognize(
            UploadedFile::fake()->create('ledger.jpg', 10, 'image/jpeg')
        );
    }

    public function test_it_explains_a_model_not_found_response(): void
    {
        config([
            'services.gemini.api_key' => 'test-api-key',
            'services.gemini.model' => 'gemini-unavailable',
        ]);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/interactions' => Http::response([
                'error' => [
                    'message' => 'This model is no longer available to new users. Use gemini-3.8-flash.',
                    'status' => 'NOT_FOUND',
                ],
            ], 404),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This model is no longer available to new users. Use gemini-3.8-flash.');

        app(GeminiLedgerRecognizer::class)->recognize(
            UploadedFile::fake()->create('ledger.jpg', 10, 'image/jpeg')
        );
    }

    public function test_it_retries_temporary_gemini_overload_before_returning_records(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/interactions' => Http::sequence()
                ->push([
                    'error' => ['message' => 'The model is currently experiencing high demand.'],
                ], 503, ['Retry-After' => '0'])
                ->push([
                    'output_text' => json_encode(['records' => [[
                        'payment_date' => '2026-05-01',
                        'or_number' => '12345',
                        'amount' => '150.00',
                        'uncertain_fields' => [],
                    ]]]),
                ]),
        ]);

        $records = app(GeminiLedgerRecognizer::class)->recognize(
            UploadedFile::fake()->create('ledger.jpg', 10, 'image/jpeg')
        );

        $this->assertCount(1, $records);
        $this->assertSame('12345', $records[0]['or_number']);
        Http::assertSentCount(2);
    }

    public function test_it_returns_a_retryable_error_after_temporary_overload_persists(): void
    {
        config(['services.gemini.api_key' => 'test-api-key']);
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/interactions' => Http::sequence()
                ->push([
                    'error' => ['message' => 'The model is currently experiencing high demand.'],
                ], 503, ['Retry-After' => '0'])
                ->push([
                    'error' => ['message' => 'The model is currently experiencing high demand.'],
                ], 503, ['Retry-After' => '0'])
                ->push([
                    'error' => ['message' => 'The model is currently experiencing high demand.'],
                ], 503, ['Retry-After' => '0']),
        ]);

        try {
            app(GeminiLedgerRecognizer::class)->recognize(
                UploadedFile::fake()->create('ledger.jpg', 10, 'image/jpeg')
            );
            $this->fail('Expected Gemini overload to remain unavailable.');
        } catch (RuntimeException $exception) {
            $this->assertSame(503, $exception->getCode());
            $this->assertStringContainsString('temporarily busy', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }
}
