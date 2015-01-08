<?php
//First lets set the timeout limit to 0 so the page wont time out.
set_time_limit(0);

$starttimer   = time();
//The server host is the IP or DNS of the IRC server.
$server_host  = "irc.quakenet.org";
//Server Port, this is the port that the irc server is running on. Deafult: 6667
$server_port  = 6667;
//Server Chanel, After connecting to the IRC server this is the channel it will join.
$server_chan  = "#somechannel";
//Set the nickName of the bot
$nickname     = 'somebot';
//set the Nickserv password
$nickServPass = "";


//Ok, We have a the info, now lets connect.
$server           = array(); //we will use an array to store all the server data.
//Open the socket connection to the IRC server
$server['SOCKET'] = fsockopen($server_host, $server_port, $errno, $errstr, 2);
if ($server['SOCKET'])
{
    //Ok, we have connected to the server, now we have to send the login commands.
    SendCommand("PASS NOPASS\n\r"); //Sends the password not needed for most servers
    SendCommand("NICK $nickname\n\r"); //sends the nickname
    SendCommand("USER $nickname  8 * : Zapsoda's Email Notification Bot\n\r"); //sends the user must have 4 paramters
    while (!feof($server['SOCKET'])) //while we are connected to the server
    {
        $server['READ_BUFFER'] = fgets($server['SOCKET'], 1024); //get a line of data from the server
        
        /*
        IRC Sends a "PING" command to the client which must be anwsered with a "PONG"
        Or the client gets Disconnected
        */
        //Now lets check to see if we have joined the server
        if (strpos($server['READ_BUFFER'], "001")) //once we recive a 376 message for end MOTD continute
        {
            //If we have joined the server
            SendCommand("PRIVMSG nickserv identify " . $nickname . $nickServPass . "\n\r"); // login to nickserv
            SendCommand("JOIN $server_chan\n\r"); //Join the chanel
        }
        
        //Listen for commands like !email
        if (strpos($server['READ_BUFFER'], "!email")) //when someone does !email
        {
            //If we have joined the server
            checkEmail(1); // start the Check emali function so that it gives a response even if there is no email
        }
        if (strpos($server['READ_BUFFER'], "!ping")) //When it sees !ping
        {
            SendCommand("PRIVMSG " . $server_chan . " :Pong \n\r"); // Say pong
        }
        // timer to check email every 5 minutes
        if (($starttimer + 300) < time()) //if the timer is the old time + 5 minutes
        {
            $starttimer = time(); // set the timer to the current time
            checkEmail(0); // call the checkEmail function without displaying emails if there are none
        }
        if (substr($server['READ_BUFFER'], 0, 6) == "PING :") //If the server has sent the ping command
        {
            SendCommand("PONG :" . substr($server['READ_BUFFER'], 6) . "\n\r"); //Reply with pong
            //As you can see i dont have it reply with just "PONG"
            //It sends PONG and the data recived after the "PING" text on that recived line
            //Reason being is some irc servers have a "No Spoof" feature that sends a key after the PING
            //Command that must be replied with PONG and the same key sent.
        }
    }
}
function SendCommand($cmd)
{
    global $server; //Extends our $server array to this function
    fwrite($server['SOCKET'], $cmd, strlen($cmd)); //sends the command to the server
}
function checkEmail($reply)
{
    /* get globals */
    global $server_chan;
    
    /* connect to gmail */
    $hostname = '{imap.gmail.com:993/imap/ssl/novalidate-cert}INBOX';
    $username = 'someemail@gmail.com';
    $password = 'somepass';
    
    /* try to connect */
    $inbox = imap_open($hostname, $username, $password) or die('Cannot connect to Gmail: ' . imap_last_error());
    
    /* grab emails */
    $emails = imap_search($inbox, 'UNSEEN');
    
    /* if emails are returned, cycle through each... */
    if ($emails)
    {
        SendCommand("PRIVMSG " . $server_chan . " :You have new email: \n\r"); //Msg list of new email
        sleep(1);

        /* begin output var */
        $output = '';
        
        /* put the newest emails on top */
        rsort($emails);
        
        /* for every email... */
        foreach ($emails as $email_number)
        {
            /* get information specific to this email */
            $overview = imap_fetch_overview($inbox, $email_number, 0);

            /****** OLD ******

                $message = imap_fetchbody($inbox, $email_number, 1.1);
                if($message == '')
                {
                $message = imap_fetchbody($inbox, $email_number, 1);
                } 
            */
            
            /* Get the IMAP message structure */
            $structure = imap_fetchstructure($inbox, $email_number);
            
            if (isset($structure->parts) && is_array($structure->parts) && isset($structure->parts[1]))
            {
                $part    = $structure->parts[1];
                $message = imap_fetchbody($inbox, $email_number, 1);

                /* Check encoding - 3 is base64 */
                if ($part->encoding == 3)
                {
                    $message = imap_base64($message);
                }
                /* 1 is 8bit */
                elseif ($part->encoding == 1)
                {
                    $message = imap_8bit($message);
                }
                else
                {
                    $message = imap_qprint($message);
                }
            }

            $message_array = preg_split("/\r?\n/", $message);
            
            
            /* output the email header information */
            $output_from = $overview[0]->from . "\n"; // set output to the subject
            $output_from = preg_replace('/<(.*?)>/', '', $output_from);
            
            $output_subject = $overview[0]->subject . "\n"; // set output to the subject
            $output_body    = $message_array;
            
            //imap_setflag_full($inbox, $email_number, "\\Seen \\Flagged"); //mark as seen
            
            SendCommand("PRIVMSG " . $server_chan . " :" . "From:  " . $output_from . "\n\r"); // list the message address
            sleep(1);
            SendCommand("PRIVMSG " . $server_chan . " :" . "Topic: " . $output_subject . "\n\r"); // list the message subjects
            sleep(1);
            SendCommand("PRIVMSG " . $server_chan . " :" . "=====\n\r"); // line break
            
            $count             = 1;
            $tmp               = "";
            $message_array_new = array();

            foreach ($message_array as $value)
            {
                /* Split on whitespace and read into $pieces array */
                $pieces = explode(" ", $value);

                /* Loop over each word in this section of the email. */
                foreach ($pieces as $piece)
                {
                    /* Check the length of this current piece */
                    $len = strlen($piece);

                    /* Max line length for IRC is 512 - if this new word is gonna push us over that or equal to it, 
                       lets queue up the message and start the next message. */

                    if (strlen($tmp) + $len >= 512)
                    {
                        array_push($message_array_new, $tmp);
                        $tmp = "";
                    }

                    /* Glue this current word into $tmp */
                    $tmp = $tmp . " " . $piece;
                }
            }

            /* If theres anything left, push it in a new message. */
            if (!empty($tmp))
                array_push($message_array_new, $tmp);
            
            
            $lines = 0;
            foreach ($message_array_new as $message_array_value)
            {
                $message_array_value = preg_replace('/\s+/', ' ', $message_array_value);

                if ($message_array_value == '')
                {
                    SendCommand("PRIVMSG " . $server_chan . " :          " . " \n\r");
                }

                SendCommand("PRIVMSG " . $server_chan . " :" . trim($message_array_value) . "\n\r"); // list the message body
                $lines++;

                /* Increment our sleep counter to prevent excess flod */
                sleep($lines + 1);
            }
            
        }
    }
    else
    {
        if ($reply == 1)
        {
            
            SendCommand("PRIVMSG " . $server_chan . " :" . "No new email." . "\n\r");
        }
    }
    /* close the connection */
    imap_close($inbox);
}
?>
