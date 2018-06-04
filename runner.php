<?php

foreach (['../../../autoload.php', '../../autoload.php', '../vendor/autoload.php', 'vendor/autoload.php'] as $autoload) {
    $autoload = __DIR__ . '/' . $autoload;
    if (file_exists($autoload)) {
        require $autoload;
        break;
    }
}

function diffTimeStampFromNow($timestampToDiff) {
    $nowDateTime = new DateTime();
    $nowTimestamp = $nowDateTime -> getTimestamp();
    return $nowTimestamp - $timestampToDiff;
}

function uploadToGBQ($tableName) {
    $command="env -i vendor/bin/console sync --delete-table ". $tableName ." 2>&1";
    echo($command . PHP_EOL);
    $output = shell_exec($command);
    echo($output . PHP_EOL);
}

if (file_exists(getcwd(). '/.env')) {
    $dotenv = new Dotenv\Dotenv(getcwd());
    $dotenv->load();
}

if (empty($excludeTables) && isset($_ENV['EXCLUDE_TABLES'])) {
    $excludeTables = explode(',', $_ENV['EXCLUDE_TABLES']);
}

if (isset($_ENV['SLEEP_TIME'])) {
    $sleepTime = $_ENV['SLEEP_TIME'];
} else {
    $sleepTime = 300;
}


while(true) {
    ####################Выбор из БД##################################
    try {

        if (empty( $_resource )) {

            $_resource = mysqli_connect(
                $_ENV['DB_HOST'],
                $_ENV['DB_USERNAME'],
                $_ENV['DB_PASSWORD'],
                "information_schema"
            );

        }

    } catch (Exception $e){
        die(mysqli_error());
    }

    $allTables = array();
    $query = "show tables from " . $_ENV['DB_DATABASE_NAME'];
    $result = mysqli_query($_resource, $query);
    while ($row = mysqli_fetch_row($result)) {
        array_push($allTables, $row[0]);
    }

    $uploadTables = array_diff($allTables, $excludeTables);

    $jsonFilePath = getcwd(). '/lastuploads';
    if (file_exists($jsonFilePath)) {
        $activeStatus = json_decode(file_get_contents($jsonFilePath), true);
        unlink($jsonFilePath);
        foreach ($uploadTables as $tableName) {
            $query = "checksum table " . $_ENV['DB_DATABASE_NAME'] . "." . $tableName;
            $result = mysqli_query($_resource, $query);
            $row = mysqli_fetch_row($result);
            if (!is_null($row[1])) {
                $checksum = $row[1];
            }
            if (isset($activeStatus[$tableName])) {
                if ($activeStatus[$tableName] != $checksum) {
                    uploadToGBQ($tableName);
                    $activeStatus[$tableName] = $checksum;
                }
            } else {
                uploadToGBQ($tableName);
                $activeStatus[$tableName] = $checksum;
            }
        }
    } else {
        foreach ($uploadTables as $tableName) {
            $query = "checksum table " . $_ENV['DB_DATABASE_NAME'] . "." . $tableName;
            $result = mysqli_query($_resource, $query);
            $row = mysqli_fetch_row($result);
            if (!is_null($row[1])) {
                $checksum = $row[1];
            }
            uploadToGBQ($tableName);
            $activeStatus[$tableName] = $checksum;
        }
    }

    ##############################################################################################################

    $json = fopen($jsonFilePath, 'a+');
    fwrite($json, json_encode($activeStatus));
    rewind($json);

    mysqli_close($_resource);
    unset($_resource);
    unset($activeStatus);
    #############################################################
    echo("Done, wait $sleepTime sec" . PHP_EOL);
    sleep($sleepTime);
}