<?php
if (php_sapi_name() !== "cli") {
    die("You may only run this inside of the PHP Command Line! If you did run this in the command line, please report: \"" . php_sapi_name() . "\" to the InstagramLive-PHP Repo!");
}

logM("Loading InstagramLive-PHP v0.5...");
set_time_limit(0);
date_default_timezone_set('America/New_York');

//Load Depends from Composer...
require __DIR__ . '/vendor/autoload.php';

use InstagramAPI\Instagram;
use InstagramAPI\Request\Live;

require_once 'config.php';
/////// (Sorta) Config (Still Don't Touch It) ///////
$debug = false;
$truncatedDebug = false;
/////////////////////////////////////////////////////

if (IG_USERNAME == "USERNAME" || IG_PASS == "PASSWORD") {
    logM("Default Username and Passwords have not been changed! Exiting...");
    exit();
}

//Login to Instagram
logM("Logging into Instagram...");
$ig = new Instagram($debug, $truncatedDebug);
try {
    $loginResponse = $ig->login(IG_USERNAME, IG_PASS);

    if ($loginResponse !== null && $loginResponse->isTwoFactorRequired()) {
        logM("Two-Factor Required! Please check your phone for an SMS Code!");
        $twoFactorIdentifier = $loginResponse->getTwoFactorInfo()->getTwoFactorIdentifier();
        print "\nType your 2FA Code from SMS> ";
        $handle = fopen("php://stdin", "r");
        $verificationCode = trim(fgets($handle));
        logM("Logging in with 2FA Code...");
        $ig->finishTwoFactorLogin(IG_USERNAME, IG_PASS, $twoFactorIdentifier, $verificationCode);
    }
} catch (\Exception $e) {
    if (strpos($e->getMessage(), "Challenge") !== false) {
        logM("Account Flagged: Please sign out of all phones and try logging into instagram.com from this computer before trying to run this script again!");
        exit();
    }
    echo 'Error While Logging in to Instagram: ' . $e->getMessage() . "\n";
    exit(0);
}

//Block Responsible for Creating the Livestream.
try {
    if (!$ig->isMaybeLoggedIn) {
        logM("Couldn't Login! Exiting!");
        exit();
    }
    logM("Logged In! Creating Livestream...");
    $stream = $ig->live->create();
    $broadcastId = $stream->getBroadcastId();
    $ig->live->start($broadcastId);
    // Switch from RTMPS to RTMP upload URL, since RTMPS doesn't work well.
    $streamUploadUrl = preg_replace(
        '#^rtmps://([^/]+?):443/#ui',
        'rtmp://\1:80/',
        $stream->getUploadUrl()
    );

    //Grab the stream url as well as the stream key.
    $split = preg_split("[" . $broadcastId . "]", $streamUploadUrl);

    $streamUrl = $split[0];
    $streamKey = $broadcastId . $split[1];

    logM("================================ Stream URL ================================\n" . $streamUrl . "\n================================ Stream URL ================================");

    logM("======================== Current Stream Key ========================\n" . $streamKey . "\n======================== Current Stream Key ========================");

    logM("^^ Please Start Streaming in OBS/Streaming Program with the URL and Key Above ^^");

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        logM("You are using Windows! Therefore, your system supports the viewing of comments and likes!\nThis window will turn into the comment and like view and console output.\nA second window will open which will allow you to dispatch commands!");
        beginListener($ig, $broadcastId, $streamUrl, $streamKey);
    } else {
        logM("You are not using Windows! Therefore, the script has been put into legacy mode. New commands may not be added to legacy mode but backend features will remain.\nIt is recommended that you use Windows for the full experience!");
        logM("Live Stream is Ready for Commands:");
        newCommand($ig->live, $broadcastId, $streamUrl, $streamKey);
    }

    logM("Something Went Super Wrong! Attempting to At-Least Clean Up!");
    $ig->live->getFinalViewerList($broadcastId);
    $ig->live->end($broadcastId);
} catch (\Exception $e) {
    echo 'Error While Creating Livestream: ' . $e->getMessage() . "\n";
}

function addLike(\InstagramAPI\Response\Model\User $user)
{
    global $cfg_callbacks;
    logM("@".$user->getUsername()." has liked the stream!");
    if (
        $cfg_callbacks &&
        isset($cfg_callbacks['like']) &&
        is_callable($cfg_callbacks['like'])
    ) {
        $cfg_callbacks['like']($user);
    }
}

function addComment(\InstagramAPI\Response\Model\Comment $comment)
{
    global $cfg_callbacks;
    logM("Comment [ID " . $comment->getMediaId() . "] @" . $comment->getUser()->getUsername() . ": " . $comment->getText());
    if (
        $cfg_callbacks &&
        isset($cfg_callbacks['comment']) &&
        is_callable($cfg_callbacks['comment'])
    ) {
        $cfg_callbacks['comment']($comment->getUser(), $comment);
    }
}

function beginListener(Instagram $ig, string $broadcastId, $streamUrl, $streamKey)
{
    pclose(popen("start \"Command Line Input\" ".PHP_BINARY." commandLine.php", "r"));
    cli_set_process_title("Live Chat and Like Output");
    $lastCommentTs = 0;
    $lastLikeTs = 0;
    $exit = false;

    @unlink(__DIR__ . '/request');

    do {
        $cmd = '';
        $values = [];
        /** @noinspection PhpComposerExtensionStubsInspection */
        $request = json_decode(@file_get_contents(__DIR__ . '/request'), true);
        if (!empty($request)) {
            $cmd = $request['cmd'];
            $values = $request['values'];
        }
        if ($cmd == 'ecomments') {
            $ig->live->enableComments($broadcastId);
            logM("Enabled Comments!");
            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'dcomments') {
            $ig->live->disableComments($broadcastId);
            logM("Disabled Comments!");
            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'end') {
            $archived = $values[0];
            logM("Wrapping up and exiting...");
            //Needs this to retain, I guess?
            $ig->live->getFinalViewerList($broadcastId);
            $ig->live->end($broadcastId);
            if ($archived == 'yes') {
                $ig->live->addToPostLive($broadcastId);
                logM("Livestream added to Archive!");
            }
            logM("Ended stream!");
            unlink(__DIR__ . '/request');
            sleep(2);
            exit();
        } elseif ($cmd == 'url') {
            logM("================================ Stream URL ================================\n" . $streamUrl . "\n================================ Stream URL ================================");
            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'key') {
            logM("======================== Current Stream Key ========================\n" . $streamKey . "\n======================== Current Stream Key ========================");
            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'info') {
            $info = $ig->live->getInfo($broadcastId);
            $status = $info->getStatus();
            $muted = var_export($info->is_Messages(), true);
            $count = $info->getViewerCount();
            logM("Info:\nStatus: $status \nMuted: $muted \nViewer Count: $count");
            unlink(__DIR__ . '/request');
        } elseif ($cmd == 'viewers') {
            $output = '';
            $ig->live->getInfo($broadcastId);
            foreach ($ig->live->getViewerList($broadcastId)->getUsers() as &$cuser) {
                $output .= "@" . $cuser->getUsername() . " (" . $cuser->getFullName() . ")\n";
            }
            logM($output);
            unlink(__DIR__ . '/request');
        }
        // Get broadcast comments.
        // - The latest comment timestamp will be required for the next
        //   getComments() request.
        // - There are two types of comments: System comments and user comments.
        //   We compare both and keep the newest (most recent) timestamp.
        $commentsResponse = $ig->live->getComments($broadcastId, $lastCommentTs);
        $systemComments = $commentsResponse->getSystemComments();
        $comments = $commentsResponse->getComments();
        if (!empty($systemComments)) {
            $lastCommentTs = end($systemComments)->getCreatedAt();
        }
        if (!empty($comments) && end($comments)->getCreatedAt() > $lastCommentTs) {
            $lastCommentTs = end($comments)->getCreatedAt();
        }
        foreach ($comments as $comment) {
            addComment($comment);
        }
        // Get broadcast heartbeat and viewer count.
        $ig->live->getHeartbeatAndViewerCount($broadcastId);
        // Get broadcast like count.
        // - The latest like timestamp will be required for the next
        //   getLikeCount() request.
        $likeCountResponse = $ig->live->getLikeCount($broadcastId, $lastLikeTs);
        $lastLikeTs = $likeCountResponse->getLikeTs();
        foreach ($likeCountResponse->getLikers() as $user) {
            $user = $ig->people->getInfoById($user->getUserId())->getUser();
            addLike($user);
        }
        sleep(2);
    } while (!$exit);
}

/**
 * The handler for interpreting the commands passed via the command line.
 */
function newCommand(Live $live, $broadcastId, $streamUrl, $streamKey)
{
    print "\n> ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    if ($line == 'ecomments') {
        $live->enableComments($broadcastId);
        logM("Enabled Comments!");
    } elseif ($line == 'dcomments') {
        $live->disableComments($broadcastId);
        logM("Disabled Comments!");
    } elseif ($line == 'stop' || $line == 'end') {
        fclose($handle);
        //Needs this to retain, I guess?
        $live->getFinalViewerList($broadcastId);
        $live->end($broadcastId);
        logM("Stream Ended!\nWould you like to keep the stream archived for 24 hours? Type \"yes\" to do so or anything else to not.");
        print "> ";
        $handle = fopen("php://stdin", "r");
        $archived = trim(fgets($handle));
        if ($archived == 'yes') {
            logM("Adding to Archive!");
            $live->addToPostLive($broadcastId);
            logM("Livestream added to archive!");
        }
        logM("Wrapping up and exiting...");
        exit();
    } elseif ($line == 'url') {
        logM("================================ Stream URL ================================\n" . $streamUrl . "\n================================ Stream URL ================================");
    } elseif ($line == 'key') {
        logM("======================== Current Stream Key ========================\n" . $streamKey . "\n======================== Current Stream Key ========================");
    } elseif ($line == 'info') {
        $info = $live->getInfo($broadcastId);
        $status = $info->getStatus();
        $muted = var_export($info->is_Messages(), true);
        $count = $info->getViewerCount();
        logM("Info:\nStatus: $status\nMuted: $muted\nViewer Count: $count");
    } elseif ($line == 'viewers') {
        logM("Viewers:");
        $live->getInfo($broadcastId);
        foreach ($live->getViewerList($broadcastId)->getUsers() as &$cuser) {
            logM("@" . $cuser->getUsername() . " (" . $cuser->getFullName() . ")");
        }
    } elseif ($line == 'help') {
        logM("Commands:\nhelp - Prints this message\nurl - Prints Stream URL\nkey - Prints Stream Key\ninfo - Grabs Stream Info\nviewers - Grabs Stream Viewers\necomments - Enables Comments\ndcomments - Disables Comments\nstop - Stops the Live Stream");
    } else {
        logM("Invalid Command. Type \"help\" for help!");
    }
    fclose($handle);
    newCommand($live, $broadcastId, $streamUrl, $streamKey);
}

/**
 * Logs a message in console but it actually uses new lines.
 */
function logM($message)
{
    print $message . "\n";
}