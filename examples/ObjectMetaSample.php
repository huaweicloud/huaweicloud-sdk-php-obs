<?php

/**
 * This sample demonstrates how to set/get self-defined metadata for object
 * on OBS using the OBS SDK for PHP.
 */
if (file_exists ( 'vendor/autoload.php' )) {
	require 'vendor/autoload.php';
} else {
	require '../vendor/autoload.php'; // sample env
}

if (file_exists ( 'obs-autoloader.php' )) {
	require 'obs-autoloader.php';
} else {
	require '../obs-autoloader.php'; // sample env
}

use Obs\ObsClient;
use Obs\ObsException;

$ak = '*** Provide your Access Key ***';

$sk = '*** Provide your Secret Key ***';

$endpoint = 'https://your-endpoint:443';

$bucketName = 'my-obs-bucket-demo';

$objectKey = 'my-obs-object-key-demo';


/*
 * Constructs a obs client instance with your account for accessing OBS
 */
$obsClient = ObsClient::factory ( [
		'key' => $ak,
		'secret' => $sk,
		'endpoint' => $endpoint,
		'socket_timeout' => 30,
		'connect_timeout' => 10
] );

try
{
	/*
	 * Create bucket
	 */
	printf("Create a new bucket for demo\n\n");
	$obsClient -> createBucket(['Bucket' => $bucketName]);
	
	/*
	 * Create object
	 */
	$content = 'Hello OBS';
	
	/*
	 * Setting self-defined metadata
	 */
	
	$metadata = [];
	
	$metadata['meta1'] = 'value1';
	$metadata['meta2'] = 'value2';
	$obsClient -> putObject(['Bucket' => $bucketName, 'Key' => $objectKey, 'Body' => $content, 'Metadata' => $metadata]);
	
	printf("Create object %s successfully!\n\n", $objectKey);
	
	/*
	 * Get object metadata
	 */
	$resp = $obsClient -> getObjectMetadata(['Bucket' => $bucketName, 'Key' => $objectKey]);
	printf("Getting object metadata:\n");
	foreach ($resp['Metadata'] as $key => $value){
		printf("\t%s=%s\n", $key, $value);
	}
	
	/*
	 * Delete object
	 */
	$obsClient -> deleteObject(['Bucket' => $bucketName, 'Key' => $objectKey]);
	
	
} catch ( ObsException $e ) {
	echo 'Response Code:' . $e->getStatusCode () . PHP_EOL;
	echo 'Error Message:' . $e->getExceptionMessage () . PHP_EOL;
	echo 'Error Code:' . $e->getExceptionCode () . PHP_EOL;
	echo 'Request ID:' . $e->getRequestId () . PHP_EOL;
	echo 'Exception Type:' . $e->getExceptionType () . PHP_EOL;
} finally{
	$obsClient->close ();
}