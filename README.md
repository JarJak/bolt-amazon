Amazon AWS S3 Integration
=========================

Provides AWS Storage as a Filesystem so all uploaded files, images and thumbs will be saved there.
Those assets will be still served through Bolt's proxy.

Requirements
------------


Configuration
-------------

After installation, a configuration file will be created as
`app/config/extensions/amazon.jarjak.yml`, where you have to update fields with your S3 bucket config.

Mandatory options are:
 - bucket_region
 - bucket_name

For auth it uses env variables:
 - AWS_ACCESS_KEY_ID
 - AWS_SECRET_ACCESS_KEY
 
Because of efficiency problem you can use limit for file listings by env variable:
 - AWS_FILE_LIST_LIMIT
If it is not defined in env file or set for 0 than limit is disabled.

This extension needs a Cache Driver, which can be Redis or just Array. 

If you have RedisClient but you haven't configured it yet (registered as `predis_cache`), it will create one for you with the following env variables:
 - REDIS_HOST
 - REDIS_PORT
 - REDIS_PREFIX