<?php
logM("Please wait while while the command line ensures that the live script is properly started!");
sleep(2);
logM("Command Line Ready! Type \"help\" for help.");
newCommand();


function newCommand()
{
    print "\n> ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    if ($line == 'ecomments') {
        sendRequest("ecomments", null);
        logM("Enabled Comments!");
    } elseif ($line == 'dcomments') {
        sendRequest("dcomments", null);
        logM("Disabled Comments!");
    } elseif ($line == 'stop' || $line == 'end') {
        fclose($handle);
        logM("Would you like to keep the stream archived for 24 hours? Type \"yes\" to do so or anything else to not.");
        print "> ";
        $handle = fopen("php://stdin", "r");
        $archived = trim(fgets($handle));
        if ($archived == 'yes') {
            sendRequest("end", ["yes"]);
        } else {
            sendRequest("end", ["no"]);
        }
        logM("Command Line Exiting! Stream *should* be ended.");
        sleep(2);
        exit();
    } elseif ($line == 'pin') {
        fclose($handle);
        logM("Please enter the comment id you would like to pin.");
        print "> ";
        $handle = fopen("php://stdin", "r");
        $commentId = trim(fgets($handle));
        //TODO add comment id length check
        logM("Assuming that was a valid comment id, the comment should be pinned!");
        sendRequest("pin", [$commentId]);
    } elseif ($line == 'unpin') {
        logM("Please check the other window to see if the unpin succeeded!");
        sendRequest("unpin", null);
    } elseif ($line == 'pinned') {
        logM("Please check the other window to see the pinned comment!");
        sendRequest("pinned", null);
    } elseif ($line == 'comment') {
        fclose($handle);
        logM("Please enter what you would like to comment.");
        print "> ";
        $handle = fopen("php://stdin", "r");
        $text = trim(fgets($handle));
        logM("Commented! Check the other window to ensure the comment was made!");
        sendRequest("comment", [$text]);
    } elseif ($line == 'url') {
        logM("Please check the other window for your stream url!");
        sendRequest("url", null);
    } elseif ($line == 'key') {
        logM("Please check the other window for your stream key!");
        sendRequest("key", null);
    } elseif ($line == 'info') {
        logM("Please check the other window for your stream info!");
        sendRequest("info", null);
    } elseif ($line == 'viewers') {
        logM("Please check the other window for your viewers list!");
        sendRequest("viewers", null);
    } elseif ($line == 'help') {
        logM("Commands:\nhelp - Prints this message\nurl - Prints Stream URL\nkey - Prints Stream Key\ninfo - Grabs Stream Info\nviewers - Grabs Stream Viewers\necomments - Enables Comments\ndcomments - Disables Comments\npin - Pins a Comment\nunpin - Unpins a comment if one is pinned\npinned - Gets the currently pinned comment\ncomment - Comments on the stream\nstop - Stops the Live Stream");
    } else {
        logM("Invalid Command. Type \"help\" for help!");
    }
    fclose($handle);
    newCommand();
}

function sendRequest(string $cmd, $values)
{
    /** @noinspection PhpComposerExtensionStubsInspection */
    file_put_contents(__DIR__ . '/request', json_encode([
        'cmd' => $cmd,
        'values' => isset($values) ? $values : [],
    ]));
    logM("Please wait while we ensure the live script has received our request.");
    sleep(2);
}

/**
 * Logs a message in console but it actually uses new lines.
 */
function logM($message)
{
    print $message . "\n";
}