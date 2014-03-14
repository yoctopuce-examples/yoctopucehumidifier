<?php
//Some global definition to acces the mysql database and find the Yoctopuce PHP library
define('FRIDAYDB_SERVER', 'localhost');
define('FRIDAYDB_NAME', 'fridaydb1');
define('FRIDAYDB_USER', 'test');
define('FRIDAYDB_PASSWORD', '');
define('YOCTOPUCE_LIB_PATH', '');
define('MIN_HUMIDITY', 45);
define('MAX_HUMIDITY', 55);

include(YOCTOPUCE_LIB_PATH . "yocto_api.php");
include(YOCTOPUCE_LIB_PATH . "yocto_temperature.php");
include(YOCTOPUCE_LIB_PATH . "yocto_humidity.php");
include(YOCTOPUCE_LIB_PATH . "yocto_pressure.php");
include(YOCTOPUCE_LIB_PATH . "yocto_display.php");
include(YOCTOPUCE_LIB_PATH . "yocto_relay.php");
include(YOCTOPUCE_LIB_PATH . "yocto_files.php");


/**
 * Output the a error message and stop the script.
 * the message is diplayed on the Yocto-Display if it is connected
 * and printed on the socket.
 *
 * @param $msg : the error message
 */
function Error($msg)
{
    global $display;
    /** @var $display YDisplay */
    if ($display != NULL) {
        $display->resetAll();
        // reteive the first layer
        /** @var $l0 YDisplayLayer */
        $l0 = $display->get_displayLayer(0);
        // output the error message
        $l0->consoleOut('ERROR:' . $msg);
    }
    die($msg);
}


/**
 * switch on or off the connected humidifier. Frist we cut the power for 500ms to
 * reset the humidifier to his default state (OFF). Then if we want to sitch it on
 * we need to simulate the push of the power button.
 * @param bool $on
 */
function setHumidifierState($on = False)
{
    /** @var $humPower YRelay */
    /** @var $humSwitch YRelay */
    global $humPower, $humSwitch;
    $humPower->pulse(500);
    if ($on) {
        $humSwitch->delayedPulse(500, 100);
    }

}

// setup Yoctopuce API to work in HTTP Callback
if (YAPI::RegisterHub("callback", $errmsg) != YAPI_SUCCESS)
    Error($errmsg);

/** @var $tempSensor YTemperature */
/** @var $humSenor YHumidity */
/** @var $pressSensor YPressure */
/** @var $display YDisplay */
/** @var $humPower YRelay */
/** @var $humSwitch YRelay */
$tempSensor = YTemperature::FirstTemperature();
$humSenor = YHumidity::FirstHumidity();
$pressSensor = YPressure::FirstPressure();
$display = YDisplay::FirstDisplay();
$humPower = YRelay::FindRelay("hum_power");
$humSwitch = YRelay::FindRelay("hum_switch");

// ensure that the humidifier is detected
if (!$humPower->isOnline() || !$humSwitch->isOnline()) {
    Error("Humidifier is not connected or badly configured");
}

$currentTemp = (int)$tempSensor->get_currentValue();
$currentHum = (int)$humSenor->get_currentValue();
$currentPres = (int)$pressSensor->get_currentValue();
$humstate = False;
if ($currentHum < MIN_HUMIDITY) {
    //switch On Humidifier
    $humstate = True;
    setHumidifierState(True);
} else if ($currentHum > MAX_HUMIDITY) {
    $humstate = False;
    //switch Off Humidifier
    setHumidifierState(false);
}

if (defined(DISPLAY_GRAPH)) {

    // setup database connection
    $DbConnection = mysql_connect(FRIDAYDB_SERVER, FRIDAYDB_USER, FRIDAYDB_PASSWORD);
    if (!$DbConnection) {
        Error("Unable to connect to Database");
    }
    if (!mysql_select_db(FRIDAYDB_NAME, $DbConnection)) {
        Error("Unable to select db " . FRIDAYDB_NAME);
    }

    $timestamp = time();
    $res = mysql_query("insert into states (timestamp,temperature,humidity,pressure,humidifier) values ($timestamp,$currentTemp,$currentHum,$currentPres,$humstate)", $DbConnection);
    if (!$res) {
        Error(mysql_error());
    }
}


if ($display->isOnline()) {
    // clear potential error message from previous run
    /** @var $l0 YDisplayLayer */
    $l0 = $display->get_displayLayer(0);
    $l0->clearConsole();
    $width = $display->get_displayWidth();
    $height = $display->get_displayHeight();
    /** @var $layer4 YDisplayLayer */
    $layer4 = $display->get_displayLayer(4);
    $layer4->reset();
    $layer4->hide();

    if (defined(DISPLAY_GRAPH)) {
        $history = Array();
        for ($i = 0; $i < 96; $i++) {
            $history[$i] = array("temp" => 0.0, "hum" => 0.0, "press" => 0.0, "count" => 0);
        }

        $timestamp = time();
        $lastTemp = 0;
        $lastHum = 0;

        // fetch data from database, group  by 15 min clusters
        $result = mysql_query("select * from states where timestamp>" . ($timestamp - 86400) . " order by timestamp", $DbConnection);
        if (!$result)
            Error(mysql_error());
        while ($row = mysql_fetch_array($result)) {
            /** @var $index int */
            $index = (int)($timestamp - $row["timestamp"]) / 300;
            $history[$index]["hum"] += $row["humidity"];
            $history[$index]["count"] += 1;
        }
        // compute average
        $hmin = 999;
        $hmax = -999;
        for ($i = 0; $i < sizeof($history); $i++) {
            if ($history[$i]["count"] > 0) {
                $history[$i]["hum"] = $history[$i]["hum"] / $history[$i]["count"];
                if ($history[$i]["hum"] > $hmax)
                    $hmax = $history[$i]["hum"];
                if ($history[$i]["hum"] < $hmin)
                    $hmin = $history[$i]["hum"];
            }
        }
        $layer4->selectFont('Small.yfm');
        $layer4->drawText(0, $height - 1, Y_ALIGN_BOTTOM_LEFT, sprintf("T: %d°", $currentTemp));
        if ($height > 32)
            $layer4->drawText($width / 2, $height - 1, Y_ALIGN_BOTTOM_CENTER, date('H:i'));

        $middle = (($hmax + $hmin) / 2);
        $middleScale = 5 * round($middle / 5);
        for ($i = $middleScale - 15; $i <= $middleScale + 15; $i += 5) {
            $layer4->drawText($width - 1, round(($height / 2) - 2 * ($i - $middle)), Y_ALIGN_CENTER_RIGHT, $i);
            $layer4->moveTo($width - 14, round(($height / 2) - 2 * ($i - $middle)));
            $layer4->lineTo($width - 13, round(($height / 2) - 2 * ($i - $middle)));
        }

        $i = 0;
        if ($history[$i]["count"] > 0)
            $layer4->moveTo($width - 15, round(($height / 2) - ($history[$i]["hum"] - $middle) * 2));

        for ($i = 0; $i < sizeof($history); $i++)
            if ($history[$i]["count"] > 0)
                $layer4->lineTo($width - $i - 15, round($height / 2 - ($history[$i]["hum"] - $middle) * 2));

        $lastHumText = sprintf("%d %%", $currentHum);
        $layer4->selectFont('Large.yfm');
        // draw background
        $layer4->selectColorPen(0);
        $layer4->drawText($width / 2 - 1, $height / 2, Y_ALIGN_CENTER, $lastHumText);
        $layer4->drawText($width / 2 + 1, $height / 2, Y_ALIGN_CENTER, $lastHumText);
        $layer4->drawText($width / 2, $height / 2 + 1, Y_ALIGN_CENTER, $lastHumText);
        $layer4->drawText($width / 2, $height / 2 - 1, Y_ALIGN_CENTER, $lastHumText);
        // draw lastHumText
        $layer4->selectColorPen(0xffff);
        $layer4->drawText($width / 2, $height / 2, Y_ALIGN_CENTER, $lastHumText);
    } else {
        /** @var $files YFiles */
        $module = $display->get_module();
        /** @var $files YFiles */
        $files = YFiles::FindFiles($module->get_serialNumber() . ".files");
        $list_file = $files->get_list("drop.gif");
        if (sizeof($list_file) == 0) {
            $gif_file = file_get_contents("drop.gif");
            $files->upload("drop.gif", $gif_file);
        }
        $layer4->drawImage(0, 0, "drop.gif");
        $lastHumText = sprintf("%d %%", $currentHum);
        $layer4->selectFont('Large.yfm');
        // draw lastHumText
        $layer4->selectColorPen(0xffff);
        $layer4->drawText($width, $height / 2, Y_ALIGN_CENTER_RIGHT, $lastHumText);
    }
    $display->swapLayerContent(3, 4);
}

?>