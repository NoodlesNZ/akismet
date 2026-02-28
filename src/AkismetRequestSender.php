<?php

declare(strict_types=1);

namespace NoodlesNZ\Akismet;
/**
 * Used internally by the Akismet class and to mock the Akismet anti spam service in
 * the unit tests.
 *
 * N.B. It is not necessary to implement this class to use the Akismet class.
 *
 * @package	akismet
 * @name	AkismetRequestSender
 * @author	Alex Potsides
 * @link	http://www.achingbrain.net/
 */
interface AkismetRequestSender
{
  
  /**
   *  Sends the data to the remote host.
   *
   * @param	string	$host			The host to send/receive data.
   * @param	int		$port			The port on the remote host.
   * @param	string	$request		The data to send.
   * @param	int		$responseLength	The amount of data to read.  Defaults to 1160 bytes.
   * @throws \RuntimeException If a connection cannot be made to the remote host.
   * @return string The server response
   */
  public function send(string $host, int $port, string $request, int $responseLength = 1160): string;
}
