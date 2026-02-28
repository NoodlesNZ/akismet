<?php

declare(strict_types=1);

namespace NoodlesNZ\Akismet;

/**
 * Akismet anti-comment spam service
 *
 * The class in this package allows use of the {@link http://akismet.com Akismet} anti-comment spam service in any PHP5 application.
 *
 * This service performs a number of checks on submitted data and returns whether or not the data is likely to be spam.
 *
 * Please note that in order to use this class, you must have a vaild {@link http://wordpress.com/api-keys/ WordPress API key}.  They are free for non/small-profit types and getting one will only take a couple of minutes.
 *
 * For commercial use, please {@link http://akismet.com/commercial/ visit the Akismet commercial licensing page}.
 *
 * Please be aware that this class is PHP5 only.  Attempts to run it under PHP4 will most likely fail.
 *
 * See the Akismet class documentation page linked to below for usage information.
 *
 * @package		akismet
 * @author		Alex Potsides, {@link http://www.achingbrain.net http://www.achingbrain.net}
 * @copyright	Alex Potsides, {@link http://www.achingbrain.net http://www.achingbrain.net}
 * @license		http://www.opensource.org/licenses/bsd-license.php BSD License
 */

/**
 * The Akismet PHP5 Class
 *
 * This class takes the functionality from the Akismet WordPress plugin written by {@link http://photomatt.net/ Matt Mullenweg} and allows it to be integrated into any PHP5 application or website.
 *
 * The original plugin is {@link http://akismet.com/download/ available on the Akismet website}.
 *
 * <code>
 * $akismet = new Akismet('http://www.example.com/blog/', 'aoeu1aoue');
 * $akismet->setCommentAuthor($name);
 * $akismet->setCommentAuthorEmail($email);
 * $akismet->setCommentAuthorURL($url);
 * $akismet->setCommentContent($comment);
 * $akismet->setPermalink('http://www.example.com/blog/alex/someurl/');
 *
 * if($akismet->isCommentSpam())
 *   // store the comment but mark it as spam (in case of a mis-diagnosis)
 * else
 *   // store the comment normally
 * </code>
 *
 * Optionally you may wish to check if your WordPress API key is valid as in the example below.
 *
 * <code>
 * $akismet = new Akismet('http://www.example.com/blog/', 'aoeu1aoue');
 *
 * if($akismet->isKeyValid()) {
 *   // api key is okay
 * } else {
 *   // api key is invalid
 * }
 * </code>
 *
 * @package	akismet
 * @name	Akismet
 * @author	Alex Potsides
 * @link	http://www.achingbrain.net/
 */
class Akismet
{
  private string $version = '0.5';
  private string $wordPressAPIKey;
  private string $blogURL;

  /** @var array<string, mixed> */
  private array $comment = [];
  private int $apiPort;
  private string $akismetServer;
  private string $akismetVersion;
  private AkismetRequestFactory $requestFactory;
   
   // This prevents some potentially sensitive information from being sent accross the wire.
  /** @var array<int, string> */
  private array $ignore = [
    'HTTP_COOKIE',
     'HTTP_X_FORWARDED_FOR',
     'HTTP_X_FORWARDED_HOST',
     'HTTP_MAX_FORWARDS',
     'HTTP_X_FORWARDED_SERVER',
     'REDIRECT_STATUS',
     'SERVER_PORT',
     'PATH',
     'DOCUMENT_ROOT',
     'SERVER_ADMIN',
     'QUERY_STRING',
    'PHP_SELF',
  ];
   
   /**
    * @param	string	$blogURL			The URL of your blog.
    * @param	string	$wordPressAPIKey	WordPress API key.
    */
  public function __construct(string $blogURL, string $wordPressAPIKey)
  {
    $this->blogURL = $blogURL;
    $this->wordPressAPIKey = $wordPressAPIKey;
     
     // Set some default values
    $this->apiPort = 80;
    $this->akismetServer = 'rest.akismet.com';
    $this->akismetVersion = '1.1';
    $this->requestFactory = new SocketWriteReadFactory();
     
     // Start to populate the comment data
    $this->comment['blog'] = $blogURL;
     
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
      $this->comment['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    }
     
    if (isset($_SERVER['HTTP_REFERER'])) {
      $this->comment['referrer'] = $_SERVER['HTTP_REFERER'];
    }
     
     /*
      * This is necessary if the server PHP5 is running on has been set up to run PHP4 and
      * PHP5 concurently and is actually running through a separate proxy al a these instructions:
      * http://www.schlitt.info/applications/blog/archives/83_How_to_run_PHP4_and_PHP_5_parallel.html
      * and http://wiki.coggeshall.org/37.html
      * Otherwise the user_ip appears as the IP address of the PHP4 server passing the requests to the
      * PHP5 one...
      */
    if (isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] !== getenv('SERVER_ADDR')) {
      $this->comment['user_ip'] = $_SERVER['REMOTE_ADDR'];
      return;
    }

    $forwardedFor = getenv('HTTP_X_FORWARDED_FOR');
    $this->comment['user_ip'] = $forwardedFor !== false ? $forwardedFor : '';
  }
   
   /**
    * Makes a request to the Akismet service to see if the API key passed to the constructor is valid.
    *
    * Use this method if you suspect your API key is invalid.
    *
    * @return bool	True is if the key is valid, false if not.
    */
  public function isKeyValid(): bool
  {
     // Check to see if the key is valid
     $response = $this->sendRequest('key=' . $this->wordPressAPIKey . '&blog=' . $this->blogURL, $this->akismetServer, '/' . $this->akismetVersion . '/verify-key');
    return trim($response[1]) === 'valid';
  }
   
   // makes a request to the Akismet service
  /** @return array{0: string, 1: string} */
  private function sendRequest(string $request, string $host, string $path): array
  {
     $http_request  = "POST " . $path . " HTTP/1.0\r\n";
     $http_request .= "Host: " . $host . "\r\n";
     $http_request .= "Content-Type: application/x-www-form-urlencoded; charset=utf-8\r\n";
     $http_request .= "Content-Length: " . strlen($request) . "\r\n";
     $http_request .= "User-Agent: Akismet PHP5 Class " . $this->version . " | Akismet/1.11\r\n";
     $http_request .= "\r\n";
     $http_request .= $request;
     
     $requestSender = $this->requestFactory->createRequestSender();
     $response = $requestSender->send($host, $this->apiPort, $http_request);
    
    $parts = explode("\r\n\r\n", $response, 2);
    $headers = $parts[0] ?? '';
    $body = $parts[1] ?? '';

    return [$headers, $body];
  }
   
   // Formats the data for transmission
  private function getQueryString(): string
  {
     foreach ($_SERVER as $key => $value) {
       if (!in_array($key, $this->ignore)) {
         if ($key == 'REMOTE_ADDR') {
           $this->comment[$key] = $this->comment['user_ip'];
         }
         else {
           $this->comment[$key] = $value;
         }
       }
     }
     
     $query_string = '';
     
    foreach ($this->comment as $key => $data) {
      if (!is_array($data)) {
        $query_string .= $key . '=' . urlencode(stripslashes((string) $data)) . '&';
      }
    }
     
     return $query_string;
  }
   
   /**
    * Tests for spam.
    *
    * Uses the web service provided by {@link http://www.akismet.com Akismet} to see whether or not the submitted comment is spam.  Returns a boolean value.
    *
    * @return	bool	True if the comment is spam, false if not
      * @throws \RuntimeException If the API key passed to the constructor is invalid.
    */
  public function isCommentSpam(): bool
  {
    $response = $this->sendRequest($this->getQueryString(), $this->wordPressAPIKey . '.' . $this->akismetServer, '/' . $this->akismetVersion . '/comment-check');

    if (trim($response[1]) === 'invalid' && !$this->isKeyValid()) {
      throw new \RuntimeException('The WordPress API key passed to the Akismet constructor is invalid. Please obtain a valid one from http://wordpress.com/api-keys/');
    }

    return trim($response[1]) === 'true';
  }
   
   /**
    * Submit spam that is incorrectly tagged as ham.
    *
    * Using this function will make you a good citizen as it helps Akismet to learn from its mistakes.  This will improve the service for everybody.
    */
  public function submitSpam(): void
  {
     $this->sendRequest($this->getQueryString(), $this->wordPressAPIKey . '.' . $this->akismetServer, '/' . $this->akismetVersion . '/submit-spam');
  }
   
   /**
    * Submit ham that is incorrectly tagged as spam.
    *
    * Using this function will make you a good citizen as it helps Akismet to learn from its mistakes.  This will improve the service for everybody.
    */
  public function submitHam(): void
  {
     $this->sendRequest($this->getQueryString(), $this->wordPressAPIKey . '.' . $this->akismetServer, '/' . $this->akismetVersion . '/submit-ham');
  }
   
   /**
    * To override the user IP address when submitting spam/ham later on
    *
    * @param string $userip	An IP address.  Optional.
    */
  public function setUserIP(string $userip): void
  {
     $this->comment['user_ip'] = $userip;
  }
   
   /**
    * To override the referring page when submitting spam/ham later on
    *
    * @param string $referrer	The referring page.  Optional.
    */
  public function setReferrer(string $referrer): void
  {
     $this->comment['referrer'] = $referrer;
  }
   
   /**
    * A permanent URL referencing the blog post the comment was submitted to.
    *
    * @param string $permalink	The URL.  Optional.
    */
  public function setPermalink(string $permalink): void
  {
     $this->comment['permalink'] = $permalink;
  }
   
   /**
    * The type of comment being submitted.
    *
    * May be blank, comment, trackback, pingback, or a made up value like "registration" or "wiki".
    */
  public function setCommentType(string $commentType): void
  {
     $this->comment['comment_type'] = $commentType;
  }
   
   /**
    *	The name that the author submitted with the comment.
    */
  public function setCommentAuthor(string $commentAuthor): void
  {
     $this->comment['comment_author'] = $commentAuthor;
  }
   
   /**
    * The email address that the author submitted with the comment.
    *
    * The address is assumed to be valid.
    */
  public function setCommentAuthorEmail(string $authorEmail): void
  {
     $this->comment['comment_author_email'] = $authorEmail;
  }
   
   /**
    * The URL that the author submitted with the comment.
    */
  public function setCommentAuthorURL(string $authorURL): void
  {
     $this->comment['comment_author_url'] = $authorURL;
  }
   
   /**
    * The comment's body text.
    */
  public function setCommentContent(string $commentBody): void
  {
     $this->comment['comment_content'] = $commentBody;
  }
   
   /**
    * Lets you override the user agent used to submit the comment.
    * you may wish to do this when submitting ham/spam.
    * Defaults to $_SERVER['HTTP_USER_AGENT']
    */
  public function setCommentUserAgent(string $userAgent): void
  {
     $this->comment['user_agent'] = $userAgent;
  }
   
   /**
    * Defaults to 80
    */
  public function setAPIPort(int $apiPort): void
  {
     $this->apiPort = $apiPort;
  }
   
   /**
    * Defaults to rest.akismet.com
    */
  public function setAkismetServer(string $akismetServer): void
  {
     $this->akismetServer = $akismetServer;
  }
   
   /**
    * Defaults to '1.1'
    *
    * @param string $akismetVersion
    */
  public function setAkismetVersion(string $akismetVersion): void
  {
     $this->akismetVersion = $akismetVersion;
  }
   
   /**
    * Used by unit tests to mock transport layer
    *
    * @param AkismetRequestFactory $requestFactory
    */
  public function setRequestFactory(AkismetRequestFactory $requestFactory): void
  {
     $this->requestFactory = $requestFactory;
  }
}
