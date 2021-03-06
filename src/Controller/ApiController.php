<?php
namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use App\Service\Xdag;

class ApiController extends Controller
{
	/**
     * @Route(
     *     "/api/block/{input}",
     *     name="api_block",
     *     requirements={"input"="([a-zA-Z0-9\/+]{32}|[a-f0-9]{64})"}
     * )
     */
    public function block($input, Xdag $xdag)
    {
	if (!$xdag->isReady())
                return new Response('Block explorer is currently syncing.');

		// The output is streamed directly to avoid out of memory errors
		// that is why i "hardcode" the json directly instead of
		// put everything into an array and then use json_encode
		$response = new StreamedResponse();
		$response->headers->set('Content-Type', 'application/json');

		$response->setCallback(function () use ($input, $xdag) {
			if (!$xdag->isAddress($input) && !$xdag->isBlockHash($input)) {
                       		throw new \Exception('Invalid address or block hash');
                	}

			$generator = $xdag->commandStream("block $input");

			echo "{";
			echo "\"block\":\"$input\",";

			while(true) {
				$line = $generator->current();
				$generator->next();

				if(preg_match("/Block is not found/i", $line)) {
					throw new \Exception('Block not found');
				} else if(preg_match("/Block as transaction: details/i", $line)) {
					// Jump to block as transaction
					break;
				} else if(preg_match("/\s*(.*): (.*)/ii", $line, $matches)) {
					list($key, $value) = [str_replace(' ', '_', $matches[1]), $matches[2]];
					if ($key == 'balance') {
						echo "\"balance_address\":\"" . current($balance = explode(' ', $matches[2])) . "\",";
						$value = end($balance);
					}

					echo "\"$key\":\"$value\",";
				}
			}

			echo "\"block_as_transaction\":[";

			$first = true;
			while(true) {
				$line = $generator->current();
				$generator->next();

				if(preg_match("/block as address: details/i", $line)) {
						// Jump to block as address
						break;
				} else if(preg_match("/\s*(fee|input|output|earning): ([a-zA-Z0-9\/+]{32})\s*([0-9]*\.[0-9]*)/i", $line, $matches)) {
					list(, $direction, $address, $amount) = $matches;
					if(!$first) {
						echo ",";
					}
					$first = false;
					echo "{";
					echo "\"direction\":\"$direction\",";
					echo "\"address\":\"$address\",";
					echo "\"amount\":\"$amount\"";
					echo "}";
				}
			}

			echo "],";
			echo "\"block_as_address\":[";

			$first = true;
			while(true) {
				if(!$generator->valid()) {
					break;
				}

				$line = $generator->current();
				$generator->next();

				if(preg_match("/\s*(fee|input|output|earning): ([a-zA-Z0-9\/+]{32})\s*([0-9]*\.[0-9]*)\s*(.*)/i", $line, $matches)) {
						list(, $direction, $address, $amount, $time) = $matches;
						if(!$first) {
							echo ",";
						}
						$first = false;
						echo "{";
						echo "\"direction\":\"$direction\",";
						echo "\"address\":\"$address\",";
						echo "\"amount\":\"$amount\",";
						echo "\"time\":\"$time\"";
						echo "}";
				}
			}

			echo "]";
			echo "}";
		});

		return $response;
    }

	/**
     * @Route(
     *     "/api/balance/{address}",
     *     name="api_balance",
     *     requirements={"address"="[a-zA-Z0-9\/+]{32}"}
     * )
     */
    public function balance($address, Xdag $xdag)
    {
	if (!$xdag->isReady())
                return new Response('Block explorer is currently syncing.');

		return new Response($xdag->getBalance($address));
    }
}
