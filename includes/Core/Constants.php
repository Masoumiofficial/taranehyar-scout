<?php
namespace TaranehyarScout\Core;

if (!defined('ABSPATH')) exit;

class Constants {
    const VERSION = '1.0.6';
    const TEXT_DOMAIN = 'taranehyar-scout';
    
    // Matching thresholds
    const SIMILARITY_THRESHOLD_EXACT = 95;
    const SIMILARITY_THRESHOLD_HIGH = 80;
    const SIMILARITY_THRESHOLD_MEDIUM = 60;
    
    // Job statuses
    const JOB_STATUS_QUEUED = 'queued';
    const JOB_STATUS_PROCESSING = 'processing';
    const JOB_STATUS_COMPLETED = 'completed';
    const JOB_STATUS_FAILED = 'failed';
    const JOB_STATUS_CANCELLED = 'cancelled';
    
    // Discovery statuses
    const DISCOVERY_STATUS_NEW = 'new';
    const DISCOVERY_STATUS_MATCHED = 'matched';
    const DISCOVERY_STATUS_NEEDS_REVIEW = 'needs_review';
    const DISCOVERY_STATUS_PUBLISHED = 'published';
    const DISCOVERY_STATUS_IGNORED = 'ignored';
    
    // Matching results
    const MATCH_PUBLISHED = 'published';
    const MATCH_DRAFT = 'draft';
    const MATCH_POSSIBLE_DUPLICATE = 'possible_duplicate';
    const MATCH_NOT_PUBLISHED = 'not_published';
    const MATCH_NEEDS_MANUAL = 'needs_manual_review';
    const MATCH_UNKNOWN = 'unknown';
    
    // License statuses
    const LICENSE_VERIFIED = 'verified';
    const LICENSE_NEEDS_REVIEW = 'needs_review';
    const LICENSE_METADATA_ONLY = 'metadata_only';
    const LICENSE_FILE_ALLOWED_BUT_NOT_VERIFIED = 'file_allowed_not_verified';
    const LICENSE_FORBIDDEN = 'forbidden';
    
    // Content types
    const TYPE_SINGLE = 'single';
    const TYPE_ALBUM = 'album';
    const TYPE_EP = 'ep';
    const TYPE_REMIX = 'remix';
    const TYPE_LIVE = 'live';
    const TYPE_COLLAB = 'collab';
    const TYPE_PODCAST = 'podcast';
    
    // Provider IDs
    const PROVIDER_MYBIA2MUSIC = 'mybia2music';
    const PROVIDER_MOCK = 'mock';
}
