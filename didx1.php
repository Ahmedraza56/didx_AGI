#!/usr/bin/php -q
<?php

// Setting a time limit for script execution
set_time_limit(30);

// Ignoring SIGHUP signal
pcntl_signal(SIGHUP, SIG_IGN);

// Including required PHP files and classes
require('phpagi/phpagi.php');
include_once "includes/ADb.inc.php";
include_once "includes/ADbReadOnly.inc.php";
include_once "includes/ADbSipCallOnly.inc.php";
include_once "includes/Transaction.inc.php";
include_once "includes/General.inc.php";

// Creating an instance of the AGI class
$agi = new AGI();

// Creating instances of custom classes
$myADb = new ADb();
$myTransaction = new Transaction();
$myGeneral = new General();

// Retrieving call-related information from AGI variables
$calleridnum = $agi->request['agi_callerid'];
$callerid = $agi->request['agi_callerid'];
$callidname = $agi->request['agi_calleridname'];
$phoneno = $agi->request['agi_dnid']; // Comment this line for IAX (uncomment the next line for IAX)
// $phoneno = $agi->request['agi_extension']; // Uncomment this line for IAX

$channel = $agi->request['agi_channel'];
$uniqueid = $agi->request['agi_uniqueid'];
$SystemName = "us1.didx.net";

// Handling special case where '011' prefix is removed from 'phoneno'
if (substr($phoneno, 0, 3) === "011") {
    $phoneno = substr($phoneno, 3);
}

// Constructing an SQL query to fetch information about the incoming call
$strSQL = "
    SELECT
        iURL, iFlag, BOID, orders.TalkTime, DIDS.OurPerMinuteCharges, DIDS.OutCallLimit, FreeMin,
        DIDS.OurPerMinuteCharges as PMIN, UnderCheck, CheckStatus, orders.Isdeleted, DIDS.AreaID,
        DIDS.OID as VOID, orders.Recording, orders.PayPhone, DIDS.PayPhoneBlock, orders.CLID11, CLIDCond,
        CLIDigit, DIDS.GroupID, DIDS.IsChannel, DIDS.SuspendDID, orders.MyServer, DIDS.PerMinuteCharges, NOW(),
        orders.RoundRobin, DIDS.GroupVendor, CallBack, TriggerMin, TriggerRate, OnTrigger, DIDS.iChannel
    FROM
        DIDS, orders
    WHERE
        DIDNumber = \"$phoneno\" AND BOID = orders.OID AND DIDS.Status = 2";

// Executing the SQL query to retrieve call-related information
$Result = $myADb->query($strSQL);

// Checking if the query did not return any results, indicating an invalid call
if ($Result->EOF) {
    $strSQL = "SELECT UnderCheck, CheckStatus FROM DIDS WHERE DIDNumber=\"$phoneno\"";
    $Result = $myADb->query($strSQL);

    if ($Result->EOF) {
        // Invalid call: Play "ss-noservice" and exit with congestion
        $agi->exec("Playback", "ss-noservice", "noanswer");
        $agi->exec("Congestion", "1");
        $agi->verbose("Invalid Call, This DID $phoneno is not mapped");
        exit;
    } else {
        // Valid call but under check or not yet checked
        $UnderCheck = $Result->fields[0];
        $CheckStatus = $Result->fields[1];

        if ($UnderCheck == 0) {
            $strSQL = "SELECT OID FROM DIDS WHERE DIDNumber=\"$phoneno\"";
            $Result = $myADb->query($strSQL);
            $VOID = $Result->fields[0];

            if ($VOID === 729338) {
                // Special case: Play "ss-noservice" and exit with congestion
                $agi->exec("Playback", "ss-noservice", "noanswer");
                $agi->exec("Congestion", "1");
                exit;
            } else {
                // Play "didx-not-in-service-1" and exit with congestion
                $agi->exec("Playback", "didx-not-in-service-1", "noanswer");
                $agi->exec("Congestion", "1");
                exit;
            }
        }
    }
} else {
    // Valid call, continue processing
    $UnderCheck = $Result->fields[8];
    $CheckStatus = $Result->fields[9];
    $IsDeleted = $Result->fields[10];
    $AreaID = $Result->fields[11];
    $VOID = $Result->fields[12];
    $OID = $Result->fields[2];
}

// Check if the call is under check or not checked, and update if necessary
if ($UnderCheck === "1" || $CheckStatus === "0") {
    $strSQLUpdate = "UPDATE DIDS SET CheckStatus = 1, UnderCheck = 0, BoxName=\"$SystemName\" WHERE DIDNumber = \"$phoneno\"";
    $ResultUpdate = $myADb->query($strSQLUpdate);

    // Play "didx-test-passed" and hang up
    $agi->stream_file('didx-test-passed');
    $agi->hangup();
    $strSQL = "SELECT NOW()";
    $ResultUpdate = $myADb->query($strSQL);
    $callstart = $ResultUpdate->fields[0];

    // Save CDR (Call Detail Record) for testing call
    savecdr("CALLED_BY_TESTER", "$callerid", $phoneno, "", "CANCEL", 0, 0, $callstart, 0, $OID, $callidname, "", "", 0, 0, 0, 0, 0, 0, $VOID);
    exit;
}


if ($callerid === "2126554763" || $callerid === "2125551234") {
    // Check if the caller ID matches specific values, and hang up the call if it does
    $agi->hangup();
    exit();
}

// Get the key for the caller ID
$KeyID = getKey("$callerid");

if ($IsDeleted == 6) {
    // Check if the order is in a suspended state and handle accordingly
    $agi->verbose("This order is in Suspended state, please contact customer support. $phoneno");
    $agi->exec("Playback", "didx-Suspended-state", "noanswer");
    file_get_contents("http://admin.didx.net/OhsAae2/SendSuspensionEmailOnCall.php?OID=" . $OID . "&DID=$phoneno");
    $strSQL = "SELECT NOW()";
    $ResultUpdate = $myADb->query($strSQL);
    $callstart = $ResultUpdate->fields[0];
    $TalkTime = $Result->fields[3];
    savecdr("ACCOUNT_SUSPENDED", $callerid, $phoneno, "", "CANCEL", 0, 0, $callstart, 0, $OID, $callidname, "", "", 0, $TalkTime, 0, $TalkTime, 0, 0, $VOID);
    exit;
}

// Retrieve various call-related data from the query result
$URL = $Result->fields[0];
$SuspendDID = $Result->fields[21];
$OfferRate = $Result->fields[23];

if ($SuspendDID == 1) {
    // Check if the DID is suspended and handle accordingly
    $agi->exec("Playback", "didx-temp-out-service", "noanswer");
    $strSQL = "SELECT NOW()";
    $ResultUpdate = $myADb->query($strSQL);
    $callstart = $ResultUpdate->fields[0];
    $TalkTime = $Result->fields[3];
    savecdr("DID_SUSPENDED", $callerid, $phoneno, "", "CANCEL", 0, 0, $callstart, 0, $OID, $callidname, "", "", 0, $TalkTime, 0, $TalkTime, 0, 0, $VOID);
    exit;
}

// Retrieve additional call-related data from the query result
$Flag = $Result->fields[1];
$OID = $Result->fields[2];
$TalkTime = $Result->fields[3];
$PerMinuteCharges = $Result->fields[4];
$MainPerMinute = $Result->fields[4];
$RateInCents;
$CallLimit = $Result->fields[5];
$FreeMinutes = $Result->fields[6];
$PMinAfterFree = $Result->fields[7];
$AddPlus = $Result->fields[8];
$RemoveZero = $Result->fields[9];
$CIDPrefix = $Result->fields[10];
$VOID = $Result->fields[12];
$Recording = $Result->fields[13];
$PayPhone = $Result->fields[14];
$PayPhoneBlock = $Result->fields[15]; // Updated variable name
$CLApply = $Result->fields[16];
$CLCond = $Result->fields[17];
$CLDigit = $Result->fields[18];
$GroupID = $Result->fields[19];
$IsChannel = $Result->fields[20];
$RoundRobin = $Result->fields[25];
$GroupVendor = $Result->fields[26];
$CallBack = $Result->fields[27];
$TriggerMin = $Result->fields[28];
$TriggerRate = $Result->fields[29];
$OnTrigger = $Result->fields[30];
$DefChannel = $Result->fields[31];
$KeyID = getKey($OID);

if ($IsChannel && $OnTrigger == '0') {
    // Check if it's a channel-based purchase of DID and perform related actions
    $agi->set_variable('GROUP()', "$GroupID");
    $GroupCount = $agi->get_variable("GROUP_COUNT($GroupID)");
    $GroupCountCheck = GetGroupCount();

    if ($GroupCountCheck == 0) {
        // Handle channel limit exceeded for the DID
        $agi->exec("Playback", "all-circuits-busy-now", "noanswer");
        $strSQL = "SELECT Qty FROM ChannelBuy WHERE DIDNumber=\"$phoneno\"";
        $Resultnew = $myADb->query($strSQL);
        $PurChannel = $Resultnew->fields[0];
        $emailSubject = "US1+AGI+Channels+Limit+Exceeded+on+$phoneno+Channel+Base";
        $emailContents = "Buyer:++$OID,Vendor:++$VOID,Running+Channels:++" . $GroupCount['data'] . ",Purchased+Channels:++$PurChannel,calleridnum:++$calleridnum,phoneno:++$phoneno";
        file_get_contents("http://admin.didx.net/OhsAae2/SendEmailToHB.php?Subject=$emailSubject&Contents=$emailContents");
        $strSQL = "SELECT NOW()";
        $ResultUpdate = $myADb->query($strSQL);
        $callstart = $ResultUpdate->fields[0];
        savecdr("CAPACITY_OVERLOAD", $callerid, $phoneno, "", "CANCEL", 0, 0, $callstart, 0, $OID, $callidname, "", "", 0, $TalkTime, 0, $TalkTime, 0, 0, $VOID);
        // $agi->hangup();
        exit;
    }

    $PerMinuteCharges = 0;
    $OfferRate = 0;
}

if (!$IsChannel) {
    // Check if it's not a channel-based purchase of DID
    $agi->set_variable('GROUP()', "$phoneno");
    $GroupCount = $agi->get_variable("GROUP_COUNT($phoneno)");

    if ($GroupCount['data'] > $DefChannel) {
        // Check if the number of running channels exceeds the default channel limit
        $agi->exec("Playback", "all-circuits-busy-now", "noanswer");
        $agi->exec("Verbose", "exiting " . __LINE__);
        $emailSubject = "US1+AGI+Channels+Limit+Exceeded+on+$phoneno";
        $emailContents = "Buyer:++$OID,Vendor:++$VOID,Running+Channels:++" . $GroupCount['data'] . ",Default+Channels:++$DefChannel,calleridnum:++$calleridnum,phoneno:++$phoneno";
        file_get_contents("http://admin.didx.net/OhsAae2/SendEmailToHB.php?Subject=$emailSubject&Contents=$emailContents");
        $strSQL = "SELECT NOW()";
        $ResultUpdate = $myADb->query($strSQL);
        $callstart = $ResultUpdate->fields[0];
        savecdr("CHANNEL_OVERLOAD", $callerid, $phoneno, "", "CANCEL", 0, 0, $callstart, 0, $OID, $callidname, "", "", 0, $TalkTime, 0, $TalkTime, 0, 0, $VOID);
        // $agi->hangup();
        exit;
    }
}

if ($GroupVendor >= 1) {
    // Check if a group vendor is set and apply related checks
    $agi->set_variable('GROUP()', "$VOID");
    $GroupCount = $agi->get_variable("GROUP_COUNT($VOID)");

    if ($GroupCount['data'] > $GroupVendor) {
        // Check if the number of running channels exceeds the group vendor limit
        $agi->exec("Playback", "all-circuits-busy-now", "noanswer");
        $agi->hangup();
        exit;
    }
}

if ($PayPhone == '1' && (substr("$calleridnum", 0, 2) == '70' || substr("$calleridnum", 0, 2) == '27') && strlen("$calleridnum") == 12) {
    // Check if it's a payphone call with specific caller ID conditions
    $agi->hangup();
    exit;
}


$MinSpent = getMinutesUsed($OID, $phoneno);

if ($CallBack == '1' && $OnTrigger == '1') {
    // Check if callback and trigger functionality are enabled
    $FreeTriggers = $MinSpent['Triggers'];
    $TriggersSpent = $MinSpent['TrigSpent'];

    if ($TriggersSpent > $FreeTriggers) {
        // Check if the number of triggers spent exceeds the free triggers
        CheckTalkTimeForTrigger($TalkTime);
        $TriggerCharge = $TriggerRate;
        ReduceTriggerTalkTime($TriggerRate, $OID);
    } else {
        $TriggerCharge = 0;
    }

    UpdateTrigger($OID, $phoneno);
}

if (!$IsChannel && $OnTrigger == '0') {
    // Check if it's not a channel-based purchase and trigger functionality is disabled
    $MinutesUsed = $MinSpent['Spent'];
    $Expiry = $MinSpent['Expiry'];

    if ($MinutesUsed < $FreeMinutes && $FreeMinutes > 0) {
        // Check if minutes used are less than free minutes (if applicable)
        $PerMinuteCharges = 0;
        $OfferRate = 0;
    }

    if ($MinutesUsed >= $FreeMinutes && $TalkTime <= 0) {
        // Check if minutes used are greater than or equal to free minutes, and there's no talktime
        $RateInCents = $PerMinuteCharges * 100;
        
        // Additional actions related to handling talktime
        file_get_contents("http://admin.didx.net/OhsAae2/SendIncomingMinutesEMail.php?OID=$OID&DID=$phoneno");

        $GeneralPref = $myGeneral->getUserOtherInformation($OID);

        if ($GeneralPref['TTAutoPay'] == 1) {
            // Check if automatic talktime payment is enabled
            $CurrentDollars = getTalkTimeInDollars($OID);
            $Transaction['TransactionID'] = $myTransaction->getTransactionID($OID);
            $Transaction['OID'] = $OID;
            $Transaction['Desc'] = "[DIDx: $OID] TalkTime Added";
            $Transaction['Type'] = "OINV";
            $Transaction['ReferenceID'] = $OID;
            $Transaction['Amount'] = $CurrentDollars;
            $Transaction['IsCredit'] = 1;
            $Transaction['DID'] = "";
            $IsAdded = $myTransaction->addTransaction($Transaction);

            ChargeTalkTime($OID, $CurrentDollars);
            RecordTalkTimeLog($OID, $CurrentDollars, $NodeID);
        } else {
            // Handle the case when automatic talktime payment is not enabled
            file_get_contents("http://admin.didx.net/OhsAae2/SendIncomingMinutesEMail.php?OID=$OID&DID=$phoneno");
            exit;
        }
    }
}

$pCallerID = setCallerIDSettings($callerid, $AreaID, $VOID);
$agi->set_variable('CALLERID(number)', $pCallerID);
$callerid = $pCallerID;

$trunk = '';
$recordingPath = "/var/lib/asterisk/recording";
$fileName = '';

if ($Flag == 1) {
    $dialstr = "SIP/$URL";
    $trunk = "SIP";
} elseif ($Flag == 2) {
    $dialstr = "IAX2/$URL";
    $trunk = "IAX2";
} elseif ($Flag == 4) {
    $dialstr = "SIP/supertec3439$URL@skype.didx.net:5080";
    $trunk = "SKYPE";
}

if ($Recording) {
    $fileName = "$phoneno-$callerid-$OID-$KeyID";
    $res = $agi->exec("MixMonitor $recordingPath/$fileName.wav");
    RecordToDB($OID, $recordingPath, $fileName, $phoneno, $KeyID);
}

$res = $agi->exec("DIAL $dialstr");
$dialstatus = $agi->get_variable("DIALSTATUS");
$answeredtime = $agi->get_variable("ANSWEREDTIME");
$callstart = $myGeneral->currentDbDate();

if ($dialstatus['data'] != "ANSWER") {
    // If the call is not answered, try an alternate destination
    $URL1 = getAlternateRingTo($phoneno, $OID, $dialstatus);

    if ($URL1 != '-1') {
        $URL = $URL1;
        $res = $agi->exec("DIAL $URL1");
        $dialstatus = $agi->get_variable("DIALSTATUS");
        $answeredtime = $agi->get_variable("ANSWEREDTIME");
        $callstart = $myGeneral->currentDbDate();
    }
}

if ($dialstatus['data'] == "ANSWER") {
    // If the call is answered, perform additional actions
    $agi->verbose("I am in Cutting Balance!!");
    $TTCut = ReduceBalance($OID, $RateInCents, $answeredtime['data']);
    $MinTotal = ceil($answeredtime['data'] / 60);
    UpdateCalledMinutes($OID, $phoneno, $MinTotal);
    $agi->verbose("MinUsed: $MinTotal .  MinTotal: $FreeMinutes ..   \$RateInCents: $RateInCents");
}

$pTTRemain = getTotalTalkTime($OID);
$pHash = getMinutesUsed($OID, $phoneno);
$pMinTotal = $pHash['Spent'];

savecdr($URL, $callerid, $phoneno, $trunk, $dialstatus['data'], $answeredtime['data'], $PerMinuteCharges, $callstart, $TriggerCharge, $OID, $callidname, $IP, $NodeID, $MinutesUsed, $TalkTime, $TTCut, $pTTRemain, $pHash['Expiry'], $pMinTotal, $VOID);

$agi->hangup();
PopulateMaxConnection($NodeID);
MaxCallForToday($NodeID);
UnPopulateMaxConnection($NodeID);
exit;

/////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////
////////////////////////// D-I-D-x  A-G-I  F-U-N-C-T-I-O-N-S ////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////////////////////                

function savecdr($cardnum, $callerid, $callednum, $trunk, $disposition, $billseconds, $billcost, $callstart, $resellerrate, $OID, $cidname, $pIP, $pNodeID, $pMin, $pTalkTime, $pTalkTimeCut, $pTalkTimeRemain, $pMinExp, $pMinSpent, $pVOID)
{
    global $myADb, $OfferRate, $FileName, $SystemName, $uniqueid, $channel;

    // Extract the month and year from the callstart timestamp
    $Partition = substr($callstart, 0, 7);

    // Remove the leading '+' sign from callerid and callednum
    $callerid = preg_replace("/^\+/", '', $callerid);
    $callednum = preg_replace("/^\+/", '', $callednum);

    // Define the SQL query for inserting CDR data into the database
    $query = "
    INSERT INTO cdrs (
        ringto, 
        callerid, 
        callednum, 
        trunk, 
        disposition, 
        billseconds, 
        billcost, 
        callstart, 
        resellerrate, 
        OID, 
        channel, 
        uniqueid, 
        calleridname, 
        FromIP, 
        NodeID, 
        TotalMinutes, 
        TalkTimeWas, 
        TalkTimeCut, 
        TalkTimeRemain, 
        ExpiryDate, 
        MinutesTotalUsed, 
        SystemBox, 
        RecordKey, 
        Partition, 
        Year, 
        Month, 
        Day, 
        Vendor, 
        OfferRate
    ) VALUES (
        '$cardnum',
        '$callerid',
        '$callednum',
        '$trunk',
        '$disposition',
        '$billseconds',
        '$billcost',
        '$callstart',
        '$resellerrate',
        '$OID',
        '$channel',
        '$uniqueid',
        '$cidname',
        '$pIP',
        '$pNodeID',
        '$pMin',
        '$pTalkTime',
        '$pTalkTimeCut',
        '$pTalkTimeRemain',
        '$pMinExp',
        '$pMinSpent',
        '$SystemName',
        '$FileName',
        '$Partition',
        SUBSTRING('$callstart', 1, 4),
        SUBSTRING('$callstart', 6, 2),
        SUBSTRING('$callstart', 9, 2),
        '$pVOID',
        '$OfferRate'
    )";

    // Execute the SQL query to insert the CDR data
    $Result = $myADb->query($query);
}

// Generate a unique key based on the input variable
function getKey($var)
{
    return substr(md5(dechex($var) . time() . rand() . $var), 0, 10);
}

// Check if a SIP call ID exists for a vendor in SCID_Check and SipCallID tables
function GetSipCallIDCheck($pVOID, $pCallID)
{
    $myADbSipCallOnly = new ADbSipCallOnly();
    
    // Check if the vendor OID exists in SCID_Check
    $strSQL = "SELECT * FROM SCID_Check WHERE VOID=\"$pVOID\"";
    $Resulta = $myADbSipCallOnly->query($strSQL);
    
    if ($Resulta->EOF || $Resulta->fields[0] == "") {
        return 1; // Vendor not found in SCID_Check
    }
    
    // Check if the SIP call ID exists in SipCallID
    $strSQL = "SELECT * FROM SipCallID WHERE SipCallID=\"$pCallID\"";
    $Resultb = $myADbSipCallOnly->query($strSQL);
    
    if ($Resultb->EOF || $Resultb->fields[0] == "") {
        return 1; // SIP call ID not found in SipCallID
    }
    
    return "-1"; // SIP call ID exists in both tables
}

// Insert a SIP call ID for a vendor in the SipCallID table
function SetSipCallID($pVOID, $pCallID)
{
    $myADbSipCallOnly = new ADbSipCallOnly();
    
    // Check if the vendor OID exists in SCID_Check
    $strSQL = "SELECT * FROM SCID_Check WHERE VOID=\"$pVOID\"";
    $Resulta = $myADbSipCallOnly->query($strSQL);
    
    if ($Resulta->EOF || $Resulta->fields[0] == "") {
        return; // Vendor not found in SCID_Check, so no SIP call ID is set
    }
    
    // Insert the SIP call ID into the SipCallID table
    $strSQL = "INSERT INTO SipCallID (SipCallID) VALUES (\"$pCallID\")";
    $myADbSipCallOnly->query($strSQL);
}

// Check if the quantity (Qty) exceeds the defined GroupCount for a specific GroupID
function GetGroupCount()
{
    global $myADb, $GroupID, $GroupCount;
    
    $strSQL = "SELECT Qty FROM ChannelBuy WHERE GroupID=\"$GroupID\"";
    $Result = $myADb->Execute($strSQL);
    
    if ($GroupCount['data'] > $Result->fields[0]) {
        return 0; // Capacity overload: Qty exceeds the defined GroupCount
    } else {
        return 1; // Within the capacity limit
    }
}

// Retrieve information about used minutes and triggers for a specific OID and DID
function getMinutesUsed($pOID, $pDID)
{
    global $myADb, $TriggerMin;
    
    $strSQL = "SELECT * FROM MinutesInfo WHERE OID=\"$pOID\" AND DID=\"$pDID\"";
    $Result = $myADb->Execute($strSQL);
    
    if ($Result->EOF) {
        // If no record is found, insert a new record with initial values
        $strSQL = "INSERT INTO MinutesInfo (OID, DID, MinSpent, Expiry, Triggers) VALUES ($pOID, $pDID, 0, date_add(curdate(), interval 1 month), '$TriggerMin')";
        $Result2 = $myADb->Execute($strSQL);
        $ResultDT = $myADb->Execute("SELECT curdate()");
        
        $Hash['DID'] = $pDID;
        $Hash['Spent'] = 0;
        $Hash['Expiry'] = $ResultDT->fields[0];
        return $Hash;
    }
    
    // Retrieve and return information from the existing record
    $Hash['DID'] = $Result->fields[2];
    $Hash['Spent'] = $Result->fields[3];
    $Hash['Expiry'] = $Result->fields[4];
    $Hash['Triggers'] = $Result->fields[5];
    $Hash['TrigSpent'] = $Result->fields[6];
    return $Hash; 
}

// Checks if the given talk time is less than or equal to 0 and takes necessary actions
function CheckTalkTimeForTrigger($pTalkTime)
{
    global $myADb, $myTransaction, $myGeneral, $OID, $phoneno;
    
    if ($pTalkTime <= 0) {
        // Sends an email notification
        fopen("http://admin.didx.net/OhsAae2/SendIncomingMinutesEMail.php?OID=$OID&DID=$phoneno", "r");
        
        $GeneralPref = $myGeneral->getUserOtherInformation($OID);
        
        // If TTAutoPay is enabled, adds talk time to the account and logs the transaction
        if ($GeneralPref['TTAutoPay'] == 1) {
            $CurrentDollars = getTalkTimeInDollars($OID);
            $Transaction['TransactionID'] = $myTransaction->getTransactionID($OID);
            $Transaction['OID'] = $OID;
            $Transaction['Desc'] = "[DIDx: $OID] TalkTime Added";
            $Transaction['Type'] = "OINV";
            $Transaction['ReferenceID'] = $OID;
            $Transaction['Amount'] = $CurrentDollars;
            $Transaction['IsCredit'] = 1;
            $Transaction['DID'] = "";
            $IsAdded = $myTransaction->addTransaction($Transaction);
            ChargeTalkTime($OID, $CurrentDollars);
            RecordTalkTimeLog($OID, $CurrentDollars, $NodeID);
        } else {
            exit; // Exits the script if TTAutoPay is not enabled
        }
    }
}

// Charges talk time to the specified OID
function ChargeTalkTime($pOID, $pTT)
{
    global $myADb;
    $pTT = $pTT * 100;
    $strSQL = "UPDATE orders SET talktime = talktime + $pTT WHERE oid = \"$pOID\"";
    $Result = $myADb->Execute($strSQL);
}

// Records the talk time added to the specified OID
function RecordTalkTimeLog($pOID, $pTT, $pNodeID)
{
    global $myADb;
    $strSQL = "INSERT INTO TalkTimeAdded (OID, TalkTime, Date, Location) VALUES (\"$pOID\", $pTT, now(), $pNodeID)";
    $Result = $myADb->Execute($strSQL);
}

// Retrieves the talk time in dollars for the specified OID
function getTalkTimeInDollars($pOID)
{
    global $myADb;
    $strSQL = "SELECT talktime FROM orders WHERE oid = \"$pOID\"";
    $Result = $myADb->Execute($strSQL);
    
    $Cents = $Result->fields[0];
    
    if ($Cents < 0) {
        $Cents = $Cents * (-1);
        $Dollar = sprintf("%2.2f", $Cents / 100);
        $Dollar = $Dollar + 25;
        return $Dollar;
    } else {
        return 25; // A default value of 25 dollars if the talk time is non-negative
    }
}

// Reduces the trigger talk time by the given rate for the specified OID
function ReduceTriggerTalkTime($pTRate, $OID)
{
    global $myADb;
    $pTRate = $pTRate * 100;
    $strSQL = "UPDATE orders SET TalkTime = TalkTime - $pTRate WHERE OID = \"$OID\"";
    $Result = $myADb->Execute($strSQL);
}

// Updates the trigger count for the specified OID and DID
function UpdateTrigger($pOID, $pDID)
{
    global $myADb;
    $strSQL = "UPDATE MinutesInfo SET TriggersUsed = TriggersUsed + 1 WHERE oid = \"$pOID\" AND did = \"$pDID\"";
    $Result = $myADb->Execute($strSQL);
    return;
}

// Sets caller ID settings based on specified parameters
function setCallerIDSettings($pCID, $pAreaID, $pOID)
{
    global $myADb;

    $strSQL = "SELECT AddPlus, RmZero, country, area, RemoveWat, CondApply, CondDigit FROM CallerIDSettings WHERE OID = \"$pOID\" AND AreaID = \"$pAreaID\"";
    $Result = $myADb->Execute($strSQL);

    $pPlus = $Result->fields[0];
    $pRmZero = $Result->fields[1];
    $pPrefix = $Result->fields[2] . $Result->fields[3];
    $pRmWat = $Result->fields[4];
    $Cond = $Result->fields[5];
    $CondDigit = $Result->fields[6];

    $applyCondition = GetApply($pCID, $Cond, $CondDigit);

    if ($Cond == 0 || ($applyCondition >= 1 && $applyCondition <= 3)) {
        if ($pRmZero && substr($pCID, 0, 1) == '0') {
            $pCID = substr($pCID, 1);
        }
        if (strpos($pCID, $pRmWat) === 0) {
            $pCID = substr($pCID, strlen($pRmWat));
        }
        $pCID = $pPrefix . $pCID;
        if ($pPlus) {
            $pCID = "+" . $pCID;
        }
    }

    return $pCID;
}

// Determines if a condition should be applied based on the caller ID, condition type, and digit count
function GetApply($pClid, $pCond, $pDigit)
{
    $clidLength = strlen($pClid);

    if (($pCond == 0 && $clidLength == $pDigit) || ($pCond == 1 && $clidLength > $pDigit) || ($pCond == 2 && $clidLength < $pDigit)) {
        return $pCond + 1; // 1 for success
    }

    return 0; // 0 for failure
}


// Retrieves the country name based on the given phone number
function GetCountryCode($pNumber)
{
    global $myADb;
    $pNumber = str_replace("+", "", $pNumber);
    $strSQL = "SELECT countrycode, length(countrycode), description FROM CallerCountry ORDER BY countrycode DESC";
    $Result = $myADb->Execute($strSQL);

    if ($Result->EOF) {
        return -1;
    }

    while (!$Result->EOF) {
        $myCountryCode = $Result->fields[0];
        $CLength = $Result->fields[1];
        $CName = $Result->fields[2];
        $Extr = substr($pNumber, 0, $CLength);

        if ($Extr == $myCountryCode) {
            return $CName;
        }

        $Result->MoveNext();
    }

    return -1;
}

// Records information about a call recording to the database
function RecordToDB($pUID, $pFileName, $pDID, $pKeyID, $FileData)
{
    global $myADb;
    $strSQLInsert = "INSERT INTO CallRecording (KeyID, FileName, OID, DID, File) VALUES (\"$pKeyID\", \"$pFileName\", \"$pUID\", \"$pDID\", \"$FileData\")";
    $ResultInsert = $myADb->Execute($strSQLInsert);
}

// Reduces the balance for the OID based on the rate and call duration
function ReduceBalance($oid, $Rate, $CallSec)
{
    global $myADb, $IsChannel;
    $min = ceil($CallSec / 60);
    $cut = $min * $Rate;
    if (!$IsChannel && $cut > 0) {
        $strSQL = "UPDATE orders SET TalkTime = TalkTime - $cut WHERE OID = \"$oid\"";
        $Result = $myADb->Execute($strSQL);
    }
    return $cut;
}

// Updates the total called minutes for the specified OID and DID
function UpdateCalledMinutes($pOID, $pDID, $pMin)
{
    global $myADb;
    $strSQL = "UPDATE MinutesInfo SET MinSpent = MinSpent + $pMin WHERE oid = \"$pOID\" AND did = \"$pDID\"";
    $Result = $myADb->Execute($strSQL);
}

// Retrieves the total talk time for the specified OID
function getTotalTalkTime($pOID)
{
    global $myADb;
    $strSQL = "SELECT talktime FROM orders WHERE oid = \"$pOID\"";
    $Result = $myADb->Execute($strSQL);
    return $Result->fields[0];
}

// Increments the max connection count for the specified node ID
function PopulateMaxConnection($pNodeID)
{
    global $myADb;
    $strSQL = "INSERT INTO servermaxcount (NodeID) VALUES (\"$pNodeID\") ON DUPLICATE KEY UPDATE servermaxcount = servermaxcount + 1";
    $Result = $myADb->Execute($strSQL);
}

// Records the maximum calls for the current date and node ID
function MaxCallForToday($pNodeID)
{
    global $myADb;
    $strSQL = "INSERT INTO maxcalls (Date, TotalCount, NodeID) VALUES (CURDATE(), 0, \"$pNodeID\") ON DUPLICATE KEY UPDATE TotalCount = (SELECT servermaxcount FROM servermaxcount WHERE NodeID = \"$pNodeID\")";
    $Result = $myADb->Execute($strSQL);
}

// Decrements the max connection count for the specified node ID
function UnPopulateMaxConnection($pNodeID)
{
    global $myADb;
    $strSQL = "INSERT INTO servermaxcount (NodeID) VALUES (\"$pNodeID\") ON DUPLICATE KEY UPDATE servermaxcount = servermaxcount - 1";
    $Result = $myADb->Execute($strSQL);
}

// Retrieves an alternate RingTo number based on the DID, OID, and condition
function getAlternateRingTo($pDID, $pOID, $pCond)
{
    global $myADb;
    if ($pCond == 'NOANSWER') {
        $pCond = 0;
    } elseif ($pCond == 'BUSY') {
        $pCond = 1;
    } elseif ($pCond == 'CONGESTION') {
        $pCond = 2;
    } elseif ($pCond == 'CANCEL') {
        $pCond = 3;
    } elseif ($pCond == 'CHANUNAVAIL') {
        $pCond = 4;
    }

    $strSQL = "SELECT RingTo, Flag FROM AlterRingTo WHERE (DID = \"-11\" OR DID = \"$pDID\") AND OID = \"$pOID\" AND CondType = \"$pCond\" ORDER BY DID";
    $Result = $myADb->Execute($strSQL);

    if ($Result->EOF) {
        return "-1";
    }

    $RingTo = $Result->fields[0];
    $Flag = $Result->fields[1];

    $RingTo = str_replace("DID", $pDID, $RingTo);

    if ($Flag == '1') {
        $dialstr = "SIP/" . $RingTo;
        $trunk = "SIP";
    } elseif ($Flag == '2') {
        $dialstr = "IAX2/" . $RingTo;
        $trunk = "IAX2";
    }

    return $dialstr;
}
