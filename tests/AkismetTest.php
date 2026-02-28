<?php

declare(strict_types=1);

namespace NoodlesNZ\Akismet\Tests;

use NoodlesNZ\Akismet\Akismet;
use NoodlesNZ\Akismet\AkismetRequestFactory;
use NoodlesNZ\Akismet\AkismetRequestSender;
use PHPUnit\Framework\TestCase;

class AkismetTest extends TestCase
{
  private Akismet $akismet;
  private MockRequestFactory $requestFactory;
  
  protected function setUp() : void
  {
    $this->requestFactory = new MockRequestFactory();
    
    $this->akismet = new Akismet('http://example.com', 'testKey');
    $this->akismet->setRequestFactory($this->requestFactory);
  }
  
  public function testIsKeyValid_validKey(): void
  {
    $this->requestFactory->setResponse("\r\n\r\nvalid");
    $response = $this->akismet->isKeyValid();
    $this->assertTrue($response);
  }
  
  public function testIsKeyValid_invalidKey(): void
  {
    $this->requestFactory->setResponse("\r\n\r\ninvalid");
    $response = $this->akismet->isKeyValid();
    $this->assertFalse($response);
  }
  
  public function testIsCommentSpam(): void
  {
    $this->requestFactory->setResponse("\r\n\r\ntrue");
    $response = $this->akismet->isCommentSpam();
    $this->assertTrue($response);
  }
  
  public function testIsCommentHam(): void
  {
    $this->requestFactory->setResponse("\r\n\r\nfalse");
    $response = $this->akismet->isCommentSpam();
    $this->assertFalse($response);
  }

  public function testSubmitSpam_sendsToExpectedEndpoint(): void
  {
    $this->requestFactory->setResponse("\r\n\r\nOK");
    $this->akismet->submitSpam();

    $lastCall = $this->requestFactory->getLastCall();
    $this->assertSame('testKey.rest.akismet.com', $lastCall['host']);
    $this->assertStringContainsString("POST /1.1/submit-spam HTTP/1.0\r\n", $lastCall['request']);
  }

  public function testSubmitHam_sendsToExpectedEndpoint(): void
  {
    $this->requestFactory->setResponse("\r\n\r\nOK");
    $this->akismet->submitHam();

    $lastCall = $this->requestFactory->getLastCall();
    $this->assertSame('testKey.rest.akismet.com', $lastCall['host']);
    $this->assertStringContainsString("POST /1.1/submit-ham HTTP/1.0\r\n", $lastCall['request']);
  }

  public function testRequestBody_excludesSensitiveServerVars(): void
  {
    $oldServer = $_SERVER;

    $_SERVER = [
      'REMOTE_ADDR' => '1.2.3.4',
      'HTTP_COOKIE' => 'secret=should-not-leak',
      'HTTP_USER_AGENT' => 'PHPUnit',
    ];

    $akismet = new Akismet('http://example.com', 'testKey');
    $akismet->setRequestFactory($this->requestFactory);
    $akismet->setCommentContent('hello');

    $this->requestFactory->setResponse("\r\n\r\ntrue");
    $akismet->isCommentSpam();

    $request = $this->requestFactory->getLastCall()['request'];
    $this->assertStringContainsString('comment_content=hello', $request);
    $this->assertStringContainsString('user_ip=1.2.3.4', $request);
    $this->assertStringNotContainsString('HTTP_COOKIE=', $request);

    $_SERVER = $oldServer;
  }

  public function testSetters_areIncludedInRequestBody(): void
  {
    $oldServer = $_SERVER;
    $_SERVER = ['REMOTE_ADDR' => '1.2.3.4'];

    $akismet = new Akismet('http://example.com', 'testKey');
    $akismet->setRequestFactory($this->requestFactory);
    $akismet->setCommentAuthor('Alice');
    $akismet->setCommentAuthorEmail('alice@example.com');
    $akismet->setCommentAuthorURL('https://example.com');
    $akismet->setPermalink('https://example.com/post/1');
    $akismet->setCommentContent('hello');

    $this->requestFactory->setResponse("\r\n\r\nfalse");
    $akismet->isCommentSpam();

    $request = $this->requestFactory->getLastCall()['request'];
    $this->assertStringContainsString('comment_author=Alice', $request);
    $this->assertStringContainsString('comment_author_email=alice%40example.com', $request);
    $this->assertStringContainsString('comment_author_url=https%3A%2F%2Fexample.com', $request);
    $this->assertStringContainsString('permalink=https%3A%2F%2Fexample.com%2Fpost%2F1', $request);

    $_SERVER = $oldServer;
  }

  public function testIsCommentSpam_invalidApiKeyThrows(): void
  {
    $this->requestFactory->queueResponses([
      "\r\n\r\ninvalid",
      "\r\n\r\ninvalid",
    ]);

    $this->expectException(\RuntimeException::class);
    $this->akismet->isCommentSpam();
  }
}

class MockRequestSender implements AkismetRequestSender
{
  private string $response;
  private MockRequestFactory $factory;

  public function __construct(string $response, MockRequestFactory $factory)
  {
    $this->response = $response;
    $this->factory = $factory;
  }

  public function send(string $host, int $port, string $request, int $responseLength = 1160): string
  {
    $this->factory->recordCall($host, $port, $request, $responseLength);
    return $this->response;
  }
}

class MockRequestFactory implements AkismetRequestFactory
{
  private string $defaultResponse = '';

  /** @var list<string> */
  private array $responses = [];

  /** @var list<array{host: string, port: int, request: string, responseLength: int}> */
  private array $calls = [];

  public function createRequestSender(): AkismetRequestSender
  {
    $response = count($this->responses) > 0 ? array_shift($this->responses) : $this->defaultResponse;
    return new MockRequestSender($response, $this);
  }

  public function setResponse(string $response): void
  {
    $this->defaultResponse = $response;
    $this->responses = [];
  }

  /** @param list<string> $responses */
  public function queueResponses(array $responses): void
  {
    $this->responses = $responses;
  }

  public function recordCall(string $host, int $port, string $request, int $responseLength): void
  {
    $this->calls[] = [
      'host' => $host,
      'port' => $port,
      'request' => $request,
      'responseLength' => $responseLength,
    ];
  }

  /** @return list<array{host: string, port: int, request: string, responseLength: int}> */
  public function getCalls(): array
  {
    return $this->calls;
  }

  /** @return array{host: string, port: int, request: string, responseLength: int} */
  public function getLastCall(): array
  {
    if (count($this->calls) === 0) {
      throw new \RuntimeException('No calls have been recorded');
    }

    return $this->calls[count($this->calls) - 1];
  }
}
