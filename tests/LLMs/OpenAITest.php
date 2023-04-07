<?php

namespace Kambo\Langchain\Tests\LLMs;

use PHPUnit\Framework\TestCase;
use Kambo\Langchain\LLMs\OpenAI;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI\Client;
use OpenAI\Transporters\HttpTransporter;
use OpenAI\ValueObjects\ApiKey;
use OpenAI\ValueObjects\Transporter\BaseUri;
use OpenAI\ValueObjects\Transporter\Headers;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use SplFileInfo;

use function json_encode;
use function array_merge;
use function json_decode;
use function file_get_contents;
use function is_null;
use function sys_get_temp_dir;
use function rtrim;
use function is_dir;
use function is_writable;
use function strpbrk;
use function sprintf;
use function uniqid;
use function mt_rand;
use function mkdir;

use const DIRECTORY_SEPARATOR;

class OpenAITest extends TestCase
{
    public function testExecute(): void
    {
        $openAI = $this->mockOpenAIWithResponses(
            [
                self::prepareResponse(
                    [
                        'id' => 'cmpl-6yE7cLrSIWqxXyAqwrI1HhOc5M3eu',
                        'object' => 'text_completion',
                        'created' => 1679801984,
                        'model' => 'text-davinci-003',
                        'choices' =>
                            [
                                [
                                    'text' => 'Kaleidosocks',
                                    'index' => 0,
                                    'logprobs' => null,
                                    'finish_reason' => 'stop',
                                ],
                            ],
                        'usage' => [
                            'prompt_tokens' => 15,
                            'completion_tokens' => 7,
                            'total_tokens' => 22,
                        ],
                    ]
                )
            ]
        );

        $this->assertEquals(
            'Kaleidosocks',
            $openAI('What would be a good company name for a company that makes colorful socks?')
        );
    }

    public function testSave(): void
    {
        $openAI = $this->mockOpenAIWithResponses();

        $temp = $this->createTempFolder();
        $file = $temp . DIRECTORY_SEPARATOR . 'llm_file.json';
        $openAI->save($file);

        $expectedArray = [
            'model_name' => 'text-davinci-003',
            'model' => 'text-davinci-003',
            'temperature' => 0.7,
            'max_tokens' => 256,
            'top_p' => 1,
            'frequency_penalty' => 0,
            'presence_penalty' => 0,
            'n' => 1,
            'best_of' => 1,
            'logit_bias' => [],
        ];

        $this->assertEquals($expectedArray, json_decode(file_get_contents($file), true));
    }

    public function testToString(): void
    {
        $openAI = $this->mockOpenAIWithResponses();

        // use here doc:
        $definition = <<<TEXT
\033[1mKambo\Langchain\LLMs\OpenAI\033[0m
Params: Array
(
    [model_name] => text-davinci-003
    [model] => text-davinci-003
    [temperature] => 0.7
    [max_tokens] => 256
    [top_p] => 1
    [frequency_penalty] => 0
    [presence_penalty] => 0
    [n] => 1
    [best_of] => 1
    [logit_bias] => Array
        (
        )

)

TEXT;

        $this->assertEquals($definition, (string) $openAI);
    }

    public function testGenerate(): void
    {
        $openAI = $this->mockOpenAIWithResponses(
            [
                self::prepareResponse(
                    [
                        'id' => 'cmpl-6yFFzdFW5xyM4UDJqG5u3EcIWNXOW',
                        'object' => 'text_completion',
                        'created' => 1679816347,
                        'model' => 'text-davinci-003',
                        'choices' =>
                            [
                                [
                                    'text' => 'Q: What did the fish say when it swam into a wall? A: Dam!',
                                    'index' => 0,
                                    'logprobs' => null,
                                    'finish_reason' => 'stop',
                                ],
                                [
                                    'text' => 'Roses are red, Violets are blue, Sugar is sweet, And so are you!',
                                    'index' => 1,
                                    'logprobs' => null,
                                    'finish_reason' => 'stop',
                                ],
                            ],
                        'usage' => [
                            'prompt_tokens' => 8,
                            'completion_tokens' => 48,
                            'total_tokens' => 56,
                        ],
                    ]
                )
            ]
        );

        $result = $openAI->generate(['Tell me a joke', 'Tell me a poem']);

        $this->assertEquals(
            'Q: What did the fish say when it swam into a wall? A: Dam!',
            $result->getFirstGenerationText()
        );

        $answers = [];
        foreach ($result->getGenerations() as $generation) {
            foreach ($generation as $gen) {
                $answers[] = $gen->text;
            }
        }

        $this->assertEquals(
            [
                'Q: What did the fish say when it swam into a wall? A: Dam!',
                'Roses are red, Violets are blue, Sugar is sweet, And so are you!',
            ],
            $answers
        );

        $this->assertEquals(
            [
                'token_usage' => [
                    'completion_tokens' => 48,
                    'prompt_tokens' => 8,
                    'total_tokens' => 56,
                ],
                'model_name' => 'text-davinci-003',
            ],
            $result->getLLMOutput()
        );
    }

    private static function prepareResponse(array $response): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($response));
    }

    private static function mockOpenAIWithResponses(array $responses = [], array $options = []): OpenAI
    {
        $mock = new MockHandler($responses);

        $client = self::client($mock);
        return new OpenAI(array_merge(['openai_api_key' => 'test'], $options), $client);
    }

    private static function client(MockHandler $mockHandler): Client
    {
        $apiKey = ApiKey::from('test');
        $baseUri = BaseUri::from('api.openai.com/v1');
        $headers = Headers::withAuthorization($apiKey);

        $handlerStack = HandlerStack::create($mockHandler);
        $client = new GuzzleClient(['handler' => $handlerStack]);

        $transporter = new HttpTransporter($client, $baseUri, $headers);

        return new Client($transporter);
    }

    private function createTempFolder(
        string $dir = null,
        string $prefix = 'tmp_',
        $mode = 0700,
        int $maxAttempts = 10
    ): SplFileInfo {
        /* Use the system temp dir by default. */
        if (is_null($dir)) {
            $dir = sys_get_temp_dir();
        }

        /* Trim trailing slashes from $dir. */
        $dir = rtrim($dir, DIRECTORY_SEPARATOR);

        /* If we don't have permission to create a directory, fail, otherwise we will
         * be stuck in an endless loop.
         */
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new RuntimeException('Target directory is not writable, dir: ' . $dir);
        }

        /* Make sure characters in prefix are safe. */
        if (strpbrk($prefix, '\\/:*?"<>|') !== false) {
            throw new RuntimeException('Character in prefix are not safe, prefix: ' . $prefix);
        }

        /**
         * Attempt to create a random directory until it works. Abort if we reach
         * $maxAttempts. Something screwy could be happening with the filesystem
         * and our loop could otherwise become endless.
         */
        for ($i = 0; $i < $maxAttempts; ++$i) {
            $path = sprintf(
                '%s%s%s%s',
                $dir,
                DIRECTORY_SEPARATOR,
                $prefix,
                uniqid((string)mt_rand(), true)
            );

            if (mkdir($path, $mode, true)) {
                return new SplFileInfo($path);
            }
        }

        throw new RuntimeException('Maximum number of attempts has been reached, prefix: ' . $i);
    }
}
