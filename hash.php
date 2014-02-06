<?php

isset ($argv[1]) or die ('Usage: ' . $argv[0] . ' [HASH_ALGORITHM] PASSWORD'. "\n");

if (isset ($argv[2])) {
    $hash = $argv[1];
    $pass = $argv[2];
    if (in_array($argv[1], hash_algos())) {
        echo $hash . ': ' . hash($hash, $pass) . "\n";
    } else {
        echo $hash . ' not available...' . "\n";
    } 
} else {
    $pass = $argv[1];
    foreach (hash_algos() as $hash) {
         echo $hash . ': ' . hash($hash, $pass) . "\n";
    }
}

?>
