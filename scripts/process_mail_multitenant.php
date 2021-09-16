#!/usr/local/bin/php
<?php
$debug = false;
$secret = 'SECRET';

try
{
    $fd = fopen( "php://stdin", "r" );
    $headers = "";
    if ($fd)
    {
        $sRawHeaders = '';
        while (trim($line = fgets($fd)) !== '')
        {
            $sRawHeaders .= $line;
        }
        $aHeaders = \explode("\n", \str_replace("\r", '', $sRawHeaders));
        $sName = null;
        $sValue = null;
        $aResult = [];
        foreach ($aHeaders as $sHeadersValue)
        {
            if (0 === strlen($sHeadersValue))
            {
                continue;
            }
            $sFirstChar = \substr($sHeadersValue, 0, 1);
            if ($sFirstChar !== ' ' && $sFirstChar !== "\t" && false === \strpos($sHeadersValue, ':'))
            {
                continue;
            }
            else if (null !== $sName && ($sFirstChar === ' ' || $sFirstChar === "\t"))
            {
                $sValue = \is_null($sValue) ? '' : $sValue;
                if ('?=' === \substr(\rtrim($sHeadersValue), -2))
                {
                    $sHeadersValue = \rtrim($sHeadersValue);
                }
                if ('=?' === \substr(\ltrim($sHeadersValue), 0, 2))
                {
                    $sHeadersValue = \ltrim($sHeadersValue);
                }
                if ('=?' === \substr($sHeadersValue, 0, 2))
                {
                    $sValue .= $sHeadersValue;
                }
                else
                {
                    $sValue .= "\n".$sHeadersValue;
                }
            }
            else
            {
                if (null !== $sName)
                {
                    if (isset($aResult[$sName]))
                    {
                        if (!is_array($aResult[$sName]))
                        {
                            $aResult[$sName] = [$aResult[$sName]];
                        }
                        $aResult[$sName][] = $sValue;
                    }
                    else
                    {
                        $aResult[$sName] = $sValue;
                    }
                    $sName = null;
                    $sValue = null;
                }
                $aHeaderParts = \explode(':', $sHeadersValue, 2);
                $sName = $aHeaderParts[0];
                $sValue = isset($aHeaderParts[1]) ? $aHeaderParts[1] : '';
                if ('?=' === \substr(\rtrim($sValue), -2))
                {
                    $sValue = \rtrim($sValue);
                }
            }
        }
        if (null !== $sName)
        {
            $aResult[$sName] = \trim($sValue);
        }
        LogMessage("Message headers");
        LogMessage(\json_encode($aResult));

        $isSpam = isset($aResult['X-Spam-Flag']) && $aResult['X-Spam-Flag'] === 'TRUE' ? true : false;
        if (!$isSpam)
        {
            $sEmail = null;
            if (isset($aResult['Received']))
            {
                $sMatch = null;
                if (is_array($aResult['Received']))
                {
                    foreach ($aResult['Received'] as $sReceived)
                    {
                        if (preg_match('/for (.*);/si', $sReceived, $matches))
                        {
                            $sMatch = $matches[1];
                            break;
                        }
                    }
                }
                else
                {
                    if (preg_match('/for (.*);/si', $aResult['Received'], $matches))
                    {
                        $sMatch = $matches[1];
                    }
                }
                if (isset($sMatch))
                {
                    $sEmail = \strtolower(\rtrim(\ltrim($matches[1], '<'), '>'));
                }
            } else {
                LogMessage('"Received" header is not found.');
            }
            if (!isset($sEmail))
            {
                if (isset($aResult['Delivered-To']))
                {
                    $sEmail = \strtolower(\rtrim(\ltrim($aResult['Delivered-To'], '<'), '>'));
                } else {
                    LogMessage('"Delivered-To" header is not found.');
                }
            }
            $sFrom = '';
            if (isset($aResult['From']))
            {
                $sFrom = \trim($aResult['From']);
            }
            $sSubject = '';
            if (isset($aResult['Subject']))
            {
                $sSubject = \trim($aResult['Subject']);
            }
            if (empty($sEmail))
            {
                LogMessage('Recipient address is not found.');
            }
            else if (empty($sFrom) && empty($sSubject))
            {
                LogMessage('"From" and "Subject" headers are not found in the mail message.');
            }
            else
            {
                $aPushMessageData = [
                    'From' => $sFrom,
                    'To' => $sEmail,
                    'Subject' => $sSubject,
                    'Folder' => 'INBOX'
                ];

                if (isset($aResult['Message-ID']))
                {
                    $aPushMessageData['MessageId'] = \trim($aResult['Message-ID']);
                }
                else
                {
                    LogMessage('"Message-ID" header is not found.');
                }

                $Data = [
                    'Email' => $sEmail,
                    'Data' => [$aPushMessageData]
                ];

                LogMessage(\json_encode($Data));

                $mResult = SendPush([$Data]);

                LogMessage(\json_encode($mResult));

            }
        }
    }
}
catch (\Exception $oEx)
{
    LogMessage('UNKNOWN_ERROR');
    LogMessage($oEx->getTraceAsString());
}

function LogMessage($Message)
{
    global $debug;
    if ($debug) {
        error_log(\gmdate('c') . ' - ' . $Message . PHP_EOL, 3, "push-notification.log");
    }
}

function SendPush($Data)
{
    global $secret;
    $rCurl = curl_init();
    curl_setopt_array($rCurl, [
        CURLOPT_URL => "https://mail.privatemail.com/?/Api/",
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            "Module" => "PushNotificator",
            "Method" => "SendPush",
            'Parameters' => \json_encode([
                'Secret' => $secret,
                'Data' => $Data
            ]) 
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_RETURNTRANSFER => true
    ]);
    return curl_exec($rCurl);
}
?>