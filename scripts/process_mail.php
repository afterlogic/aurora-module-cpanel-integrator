#!/usr/local/bin/php
<?php
$debug = true;
$secret = '';
$use_wrapper = false;
$log_prefix = 'push-script-';
$url = '';

function DecodeHeader($header) 
{
    $rfc2047header = '/=\?([^ ?]+)\?([BQbq])\?([^ ?]+)\?=/';
    $rfc2047header_spaces = '/(=\?[^ ?]+\?[BQbq]\?[^ ?]+\?=)\s+(=\?[^ ?]+\?[BQbq]\?[^ ?]+\?=)/';

    $matches = null;

    $header = preg_replace($rfc2047header_spaces, "$1$2", $header);

    if (!preg_match_all($rfc2047header, $header, $matches, PREG_SET_ORDER)) {
        return $header;
    }
    foreach ($matches as $header_match) {
        list($match, $charset, $encoding, $data) = $header_match;
        $encoding = strtoupper($encoding);
        switch ($encoding) {
            case 'B':
                $data = base64_decode($data);
                break;
            case 'Q':
                $data = quoted_printable_decode(str_replace("_", " ", $data));
                break;
            // default:
            //     throw new Exception("preg_match_all is busted: didn't find B or Q in encoding $header");
        }
        switch (strtoupper($charset)) {
            case "UTF-8":
                break;
            // default:
            //     throw new Exception("Unknown charset in header - time to write some code.");
        }
        $header = str_replace($match, $data, $header);
    }
    return $header;
}

function LogMessage($Message)
{
    global $debug, $use_wrapper, $log_prefix;
    if ($debug) {
        if ($use_wrapper) {
            \Aurora\System\Api::Log($Message, \Aurora\System\Enums\LogLevel::Full, $log_prefix);
        } else {
            error_log(\gmdate('c') . ' - ' . $Message . PHP_EOL, 3, $log_prefix . ".log");
        }
    }
}

function SendPush($Data)
{
    global $secret, $url, $use_wrapper;

    if ($use_wrapper) {
        $Secret = \Aurora\System\Api::GetModule('PushNotificator')->getConfig('Secret', '');
        \Aurora\Modules\PushNotificator\Module::Decorator()->SendPush($Secret, [$Data]);
    } else {
        $rCurl = curl_init();
        curl_setopt_array($rCurl, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                "Module" => "PushNotificator",
                "Method" => "SendPush",
                "Parameters" => \json_encode([
                    "Secret" => $secret,
                    "Data" => $Data
                ]) 
            ],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_RETURNTRANSFER => true
        ]);
        $result = curl_exec($rCurl);
		LogMessage('Response:');
        LogMessage($result);
        
        curl_close($rCurl);
    }
}

LogMessage('');
LogMessage('Processing a message...');

try {

    if ($use_wrapper) {
        require_once \dirname(__FILE__) . '/../../../system/autoload.php';
        if (!\Aurora\System\Utils::is_cli()) {
            exit("Use console");
        }
        \Aurora\System\Api::Init();
    } else {
        if (PHP_SAPI !== 'cli')
        {
            exit("Use console");
        }
    }

    $fd = fopen( "php://stdin", "r" );
    if ($fd) {
        $sRawHeaders = '';
        while (trim($line = fgets($fd)) !== '') {
            $sRawHeaders .= $line;
        }

        $aHeaders = \explode("\n", \str_replace("\r", '', $sRawHeaders));

        $sName = null;
        $sValue = null;
        $aResult = [];
        foreach ($aHeaders as $sHeadersValue) {
            if (0 === strlen($sHeadersValue)) {
                continue;
            }

            $sFirstChar = \substr($sHeadersValue, 0, 1);
            if ($sFirstChar !== ' ' && $sFirstChar !== "\t" && false === \strpos($sHeadersValue, ':')) {
                continue;
            } else if (null !== $sName && ($sFirstChar === ' ' || $sFirstChar === "\t")) {
                $sValue = \is_null($sValue) ? '' : $sValue;

                if ('?=' === \substr(\rtrim($sHeadersValue), -2)) {
                    $sHeadersValue = \rtrim($sHeadersValue);
                }

                if ('=?' === \substr(\ltrim($sHeadersValue), 0, 2)) {
                    $sHeadersValue = \ltrim($sHeadersValue);
                }

                if ('=?' === \substr($sHeadersValue, 0, 2)) {
                    $sValue .= $sHeadersValue;
                } else {
                    $sValue .= "\n".$sHeadersValue;
                }
            } else {
                if (null !== $sName) {
                    if (isset($aResult[$sName])) {
                        if (!is_array($aResult[$sName])) {
                            $aResult[$sName] = [$aResult[$sName]];
                        }
                        $aResult[$sName][] = $sValue;
                    } else {
                        $aResult[$sName] = $sValue;
                    }

                    $sName = null;
                    $sValue = null;
                }

                $aHeaderParts = \explode(':', $sHeadersValue, 2);
                $sName = $aHeaderParts[0];
                $sValue = isset($aHeaderParts[1]) ? $aHeaderParts[1] : '';

                if ('?=' === \substr(\rtrim($sValue), -2)) {
                    $sValue = \rtrim($sValue);
                }
            }
        }
        if (null !== $sName && is_string($sValue)) {
            $aResult[$sName] = \trim($sValue);
        }

        LogMessage('Message headers:');
        LogMessage(\json_encode($aResult));

        $isSpam = isset($aResult['X-Spam-Flag']) && $aResult['X-Spam-Flag'] === 'TRUE' ? true : false;
        if ($isSpam) {
            LogMessage('Spam message is detected.');
        } else {
            $sEmail = null;
            if (isset($aResult['Received'])) {
                $sMatch = null;
                if (is_array($aResult['Received'])) {
                    foreach ($aResult['Received'] as $sReceived) {
                        if (preg_match('/for (.*);/si', $sReceived, $matches)) {
                            $sMatch = $matches[1];
                            break;
                        }
                    }
                } elseif (is_string($aResult['Received']) && preg_match('/for (.*);/si', $aResult['Received'], $matches)) {
                        $sMatch = $matches[1];
                }
                if (is_string($sMatch)) {
                    $sEmail = \rtrim(\ltrim($sMatch, '<'), '>');
                }
            } else {
                LogMessage('"Received" header is not found.');
            }
            if (!isset($sEmail)) {
                if (isset($aResult['Delivered-To'])) {
                    if (is_string($aResult['Delivered-To'])) {
                        $sEmail = \rtrim(\ltrim($aResult['Delivered-To'], '<'), '>');
                    } else {
                        LogMessage('"Delivered-To" header is not a string:');
                        LogMessage(@\json_encode($aResult['Delivered-To']));
                    }
                } else {
                    LogMessage('"Delivered-To" header is not found.');
                }
            }

            $sFrom = '';
            if (isset($aResult['From'])) {
                if (is_string($aResult['From'])) {
                    $sFrom = \trim($aResult['From']);
                } else {
                    LogMessage('"From" header is not a string:');
                    LogMessage(@\json_encode($aResult['From']));
                }
            }
            $sSubject = '';
            if (isset($aResult['Subject'])) {
                if (is_string($aResult['Subject'])) {
                    $sSubject = \trim($aResult['Subject']);
                } else {
                    LogMessage('"Subject" is not a string:');
                    LogMessage(@\json_encode($aResult['Subject']));
                }
            }
            if (empty($sEmail)) {
                LogMessage('Recipient address is not found.');
            }
            else if (empty($sFrom) && empty($sSubject)) {
                LogMessage('"From" and "Subject" headers are not found in the mail message.');
            } else {
                $aPushMessageData = [
                    'From' => $sFrom,
                    'To' => $sEmail,
                    'Subject' => DecodeHeader($sSubject),
                    'Folder' => 'INBOX'
                ];

                if (isset($aResult['Message-ID'])) {
                    if (is_string($aResult['Message-ID'])) {
                        $aPushMessageData['MessageId'] = \trim($aResult['Message-ID']);
                    } else {
                        LogMessage('"Message-ID" is not a string:');
                        LogMessage(@\json_encode($aResult['Message-ID'])); 
                    }
                } else {
                    LogMessage('"Message-ID" header is not found.');
                }
                
                $Data = [
                    "Debug" => false,
                    "Email" => $sEmail,
                    "Data" => [$aPushMessageData]
                ];
				
				LogMessage('Payload:');
                LogMessage(\json_encode([$Data]));
                SendPush([$Data]);
            }
        }
    }
} catch (\Exception $oEx) {
    LogMessage('UNKNOWN_ERROR');
    LogMessage($oEx->getTraceAsString());
}