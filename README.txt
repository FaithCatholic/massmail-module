SUMMARY:

This module allows a MassTimes.org administrator to import a set of custom Excel CSV data sources and
send a bulk email to users listed in the CSV data source using Amazon AWS's Simple Email Service (SES).

INSTALLATION:

Install at /admin/modules like usual, then configure the AWS access and secret at /admin/system/massmail
(You must have the "administer site configuration" permission to configure the MassMail settings.)

Also, set orphan file deletion to "never" in /admin/config/media/file-system to prevent CSV data sources
from being deleted after a set period of time.

SENDING A MASSMAIL:

Complete and customize the form at /massmail/send (you must have the "Access MassMail mail form" permission),
upload the custom MassTimes.org CSV data source, and click the submission button to send.

(You must have the "access massmail mail form" permission to configure, test, and send mails.)

REFERENCES:

@see https://aws.amazon.com/ses/faqs/
@see http://docs.aws.amazon.com/ses/latest/DeveloperGuide/query-interface-examples.html
@see http://docs.aws.amazon.com/aws-sdk-php/v2/guide/service-ses.html
@see http://docs.aws.amazon.com/aws-sdk-php/v2/guide/service-sqs.html
@see http://docs.aws.amazon.com/ses/latest/DeveloperGuide/throughput-problems.html
@see https://github.com/daniel-zahariev/php-aws-ses
@see https://github.com/PHPOffice/PHPExcel/tree/develop
@see https://webdesign.tutsplus.com/tutorials/getting-started-with-amazon-simple-email-service-ses--cms-21688