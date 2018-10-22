Amazon AWS S3 Integration
=========================

Provides AWS Storage as a Filesystem so all uploaded files, images and thumbs will be saved there.
Those assets will be still served through Bolt's proxy.

Requirements
------------

AWS S3 Account.

Configuration
-------------

After installation, a configuration file will be created as
`app/config/extensions/amazon.jarjak.yml`, where you have to update fields with your S3 bucket config.
You can also configure everything through env variables (see `.env.dist` file).

Mandatory options are:
 - bucket_region
 - bucket_name

For auth it uses env variables (those can't be set through yaml config):
 - AWS_ACCESS_KEY_ID
 - AWS_SECRET_ACCESS_KEY
 
Because of efficiency problem you can use limit for file listings by env variable:
 - AWS_FILE_LIST_LIMIT

If it is not defined in env file or set for 0 then limit is disabled.

Using Redis as cache
--------------------
This extension needs a Cache Driver, which can be Redis or just Array. 

If you have RedisClient but you haven't configured it yet (registered as `predis_cache`), it will create one for you with the following env variables:
 - REDIS_HOST
 - REDIS_PORT
 - REDIS_PREFIX