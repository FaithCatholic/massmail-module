# MassMail

Lets a MassTimes.org administrator upload the church and services CSV exports and send a personalized bulk email to each church through Amazon SES.

Requires Drupal 10.3+ or 11, Drush 12.5+ in the project (installed by Composer), PHP's `exec()` (sending runs in the background), and a configured **private file system**. People sending mail only use the web forms; they don't need Drush or server access.

## Installation

```
composer require faithcatholic/massmail
drush en massmail
```

## Configuration

1. Set the **AWS credentials** as environment variables (see below). They are never stored in Drupal config.
2. At `/admin/config/system/massmail` (requires "administer site configuration"), set the SES region and the data directory. The data directory is relative to the private file directory, e.g. `massmail`. The page also shows whether both credentials are set.
3. Grant **Access MassMail mail form** to the role that sends mail.

## Environment variables

| Variable | Value |
|---|---|
| `MASSMAIL_AWS_ACCESS_KEY_ID` | Access key ID of the IAM user that sends through SES |
| `MASSMAIL_AWS_SECRET_ACCESS_KEY` | That user's secret access key |

Both are required. The SES region is not secret, so it's set on the settings page.

### AWS: IAM user

Use an IAM user that can only send mail, not your account's main keys. It needs this policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["ses:SendEmail", "ses:GetSendQuota"],
      "Resource": "*"
    }
  ]
}
```

The From address (and the bounce address, if set) must be verified identities in SES, in the same region you choose on the settings page.

### Upsun

Create both variables as sensitive and runtime-only. Project-level variables apply to every environment, including previews:

```
upsun variable:create -p <project-id> -l project --name env:MASSMAIL_AWS_ACCESS_KEY_ID --value '<access key id>' --sensitive true --visible-build false
upsun variable:create -p <project-id> -l project --name env:MASSMAIL_AWS_SECRET_ACCESS_KEY --value '<secret access key>' --sensitive true --visible-build false
```

To only allow sending from production, use `-l environment -e main` (or your production branch) instead of `-l project`.

In the console, the same settings are under **Project settings → Variables** (or the environment's **Variables** tab):
- Name: `env:MASSMAIL_AWS_ACCESS_KEY_ID`
- Sensitive: on
- Available during buildtime: off
- Available during runtime: on

Creating or changing a variable redeploys the environment. To rotate keys, update both values with `upsun variable:update`, then deactivate the old key in IAM.

### Local development (DDEV)

Add them to `.ddev/.env` (keep that file out of git), then run `ddev restart`:

```
MASSMAIL_AWS_ACCESS_KEY_ID=...
MASSMAIL_AWS_SECRET_ACCESS_KEY=...
```

Without DDEV, export them in the environment PHP runs in, or add `putenv('MASSMAIL_AWS_ACCESS_KEY_ID=...');` lines to `settings.local.php`.

### Checking

The settings page shows **Set** / **Not set** for each variable. From the command line:

```
drush php:eval 'var_dump((bool) getenv("MASSMAIL_AWS_ACCESS_KEY_ID"), (bool) getenv("MASSMAIL_AWS_SECRET_ACCESS_KEY"));'
```

## Sending

At `/massmail/send`:

1. Fill in the addresses, subject and body intro. Tokens such as `[[column_name]]` come from the church CSV.
2. Upload the **Church** and **Services** CSVs.
3. Choose **Save & Test** to send the first and last emails to the From address, or **Save & Send** to send to everyone.

Sending runs in the background as `drush massmail:send test|live`. `/massmail/status` shows progress, errors, and a link to the log. You can also run the command by hand, e.g. `drush massmail:send test`, to watch the output.

The bounce address is sent as the SES Return-Path, so it must be an address or domain verified in SES. If one email fails (e.g. SES rejects the address), the send logs it and carries on.

CSVs and the log are stored in `private://<data directory>`. Only users with the send permission can download them.
