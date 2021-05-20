#!/usr/local/bin/php
<?php

if (PHP_SAPI !== 'cli')
{
    exit("Use console");
}

require_once \dirname(__FILE__) . '/../../../system/autoload.php';

\Aurora\System\Api::Init();

$fd = fopen( "php://stdin", "r" );

$headers = "";

if ($fd)
{
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
                $aResult[$sName] = $sValue;

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
        $aResult[$sName] = $sValue;
    }

    \Aurora\System\Api::Log(\json_encode($aResult), \Aurora\System\Enums\LogLevel::Full, 'push-');

    $isSpam = isset($aResult['X-Spam-Flag']) && $aResult['X-Spam-Flag'] === 'TRUE' ? true : false;
    if (!$isSpam)
    {
        $sReceivedEmail = '';
        if (isset($aResult['Received']) && preg_match('/for (.*);|si/', $aResult['Received'], $matches))
        {
            $sReceivedEmail = \rtrim(\ltrim($matches[1], '<'), '>');
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
        if (empty($sReceivedEmail))
        {
            \Aurora\System\Api::Log('"Received" not found in the mail message', \Aurora\System\Enums\LogLevel::Full, 'push-');
        }
        else if (empty($sFrom))
        {
            \Aurora\System\Api::Log('"From" not found in the mail message', \Aurora\System\Enums\LogLevel::Full, 'push-');
        }
        else
        {
            $Secret = \Aurora\System\Api::GetModule('PushNotificator')->getConfig('Secret', '');
            $Data = [
                'Email' => $sReceivedEmail,
                'Data' => [[
                    'From' => $sFrom,
                    'To' => $sReceivedEmail,
                    'Subject' => $sSubject
                ]]
            ];
            \Aurora\System\Api::Log(\json_encode([$Data]), \Aurora\System\Enums\LogLevel::Full, 'push-');
            \Aurora\Modules\PushNotificator\Module::Decorator()->SendPush($Secret, [$Data]);
        }
    }
}
