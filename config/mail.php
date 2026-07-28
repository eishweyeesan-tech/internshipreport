<?php

function sendAlertEmail($to, $subject, $htmlBody, $replyTo = null) {
    $log = "[$to] $subject";
    file_put_contents(__DIR__ . '/../email.log', $log . PHP_EOL, FILE_APPEND);
    return true;
}
