# Drupal.org Integration with Tag Mapping

This document explains how to integrate data from drupal.org using the tag mapping system.

## Overview

The AI Dashboard includes a flexible tag mapping system that allows you to map flat tags from drupal.org to structured categories in your dashboard. This is essential because drupal.org uses simple tags for categorization, but your dashboard needs structured data.

## How Tag Mapping Works

### Example drupal.org Tags
When you pull issues from drupal.org, you might get tags like:
- "AI Logging"
- "June" 
- "Critical"
- "Provider Integration"
- "Drupal 11"

### Mapping to Dashboard Categories
These tags can be mapped to structured categories:

| Source Tag | Mapping Type | Mapped Value |
|------------|--------------|--------------|
| AI Logging | category | ai_integration |
| June | month | 2024-06 |
| Critical | priority | critical |
| Provider Integration | category | provider_integration |
| Drupal 11 | custom | drupal11_compatibility |

## Using the Tag Mapping Service

### Basic Usage

```php
// Get the tag mapping service
$tag_mapping_service = \Drupal::service('ai_dashboard.tag_mapping');

// Map a single tag
$category = $tag_mapping_service->mapTag('AI Logging', 'category');
// Returns: 'ai_integration'

// Map multiple tags
$tags = ['AI Logging', 'June', 'Critical'];
$categories = $tag_mapping_service->mapTags($tags, 'category');
// Returns: ['ai_integration']

// Process all tags at once
$processed = $tag_mapping_service->processTags($tags);
// Returns:
// [
//   'category' => 'ai_integration',
//   'month' => '2024-06', 
//   'priority' => 'critical',
//   'status' => null,
//   'module' => null,
//   'custom' => []
// ]
```

### Integration Example

Here's how you might use it when importing issue data from drupal.org:

```php
function importIssueFromDrupalOrg($issue_data) {
  $tag_mapping_service = \Drupal::service('ai_dashboard.tag_mapping');
  
  // Get tags from drupal.org issue
  $drupal_org_tags = $issue_data['tags']; // e.g. ['AI Logging', 'June', 'Critical']
  
  // Process tags through mapping service
  $mapped_tags = $tag_mapping_service->processTags($drupal_org_tags);
  
  // Create the issue node with mapped values
  $issue = Node::create([
    'type' => 'ai_issue',
    'title' => $issue_data['title'],
    'field_issue_number' => $issue_data['nid'],
    'field_issue_category' => $mapped_tags['category'] ?? 'general',
    'field_issue_priority' => $mapped_tags['priority'] ?? 'normal',
    'field_issue_status' => $mapped_tags['status'] ?? 'active',
    'field_issue_tags' => implode(', ', $drupal_org_tags), // Convert to comma-separated string
    // ... other fields
  ]);
  $issue->save();
}
```

## Managing Tag Mappings

### Admin Interface
Visit `/ai-dashboard/admin/tag-mappings` to manage your tag mappings:
- View all existing mappings
- Filter by mapping type
- Add new mappings
- Edit existing mappings

### Adding New Mappings
To add a new mapping:
1. Go to `/node/add/ai_tag_mapping`
2. Fill in:
   - **Source Tag**: The exact tag from drupal.org (e.g. "AI Logging")
   - **Mapping Type**: What category this maps to (category, month, priority, etc.)
   - **Mapped Value**: The structured value for your dashboard (e.g. "ai_integration")

### Mapping Types Available

- **category**: Issue categories (ai_integration, provider_integration, etc.)
- **month**: Monthly planning (2024-06, 2024-07, etc.)  
- **priority**: Issue priorities (critical, major, normal, minor)
- **status**: Issue statuses (active, needs_review, fixed, etc.)
- **module**: Module/component mappings (ai, ai_provider_openai, etc.)
- **custom**: Any other categorization you need

## Cache Management

The tag mapping service caches mappings for performance. The cache is automatically cleared when:
- A tag mapping is created, updated, or deleted
- You can manually clear with: `$tag_mapping_service->clearCache()`

## Best Practices

1. **Be Consistent**: Use consistent naming for mapped values
2. **Document Mappings**: Keep track of what each mapped value means
3. **Regular Review**: Periodically review mappings as drupal.org tags evolve
4. **Test First**: Test new mappings with sample data before going live

## Future Enhancements

The system is designed to be extensible. Future enhancements might include:
- Automatic suggestion of mappings based on tag similarity
- Bulk import/export of mappings
- API integration for real-time drupal.org synchronization
- Machine learning to suggest mappings for new tags