# Corrections

This feature allows authors to add article corrections to the top or bottom of a post.

## How it works

When you enable this feature, a new meta box will be added to the post editor screen. You can use this meta box to add a correction to the post. The correction will be displayed at the top or bottom of the post, depending on the settings.

## Usage

This feature is enabled by default. Start adding corrections to your posts by following these steps:
1. Click on Manage Corrections in Corrections & Clarifications Menu.
2. Click on Add New Correction.
3. Fill in the correction details.
4. Click on Save & Close.

## Data

Corrections are stored as `newspack_correction` custom post type. A correction consists of the following fields:

| Name                            | Type     | Stored As      | Description                                                                    |
| ------------------------------- | -------- | -------------- | ------------------------------------------------------------------------------ |
| `title`                         | `string` | `post_title`   | The correction title. Defaults to 'Correction for [post title]'                |
| `content`                       | `string` | `post_content` | The correction text.                                                           |
| `date`                          | `string` | `post_date`    | The date assigned to the correction.                                           |
| `newspack_correction-post-id`   | `int`    | `post_meta`    | The ID of the post to which the correction is associated.                      |
| `newspack_corrections_type`     | `string` | `post_meta`    | Whether it's a correction or a clarification (`correction` or `clarification`) |
| `newspack_corrections_priority` | `string` | `post_meta`    | The correction priority (`high` or `low`). Defines whether they're displayed at the top or bottom of the article.|

In addition, some correction data is stored in the associated post as post meta:

| Name                            | Type                    | Stored As   | Description                                                   |
| ------------------------------- | ----------------------- | ----------- | ------------------------------------------------------------- |
| `newspack_corrections_active`   | `bool`                  | `post_meta` | Whether the feature is enabled for the post.                  |

