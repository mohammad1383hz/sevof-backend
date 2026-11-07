<?php

namespace App\Services\Admin;

final class AnalyticsEventCatalog
{
    public const SIGNUP = 'signup';
    public const OTP_REQUESTED = 'otp_requested';
    public const OTP_VERIFIED = 'otp_verified';
    public const LOGIN_SUCCESS = 'login_success';
    public const LOGIN_FAILED = 'login_failed';
    public const SITE_ADDED = 'site_added';
    public const SITE_ANALYZED = 'site_analyzed';
    public const KEYWORD_ANALYSIS = 'keyword_analysis';
    public const CONTENT_RESEARCH_COMPLETED = 'content_research_completed';
    public const IDEA_CREATED = 'idea_created';
    public const IDEA_SCORED = 'idea_scored';
    public const ARTICLE_STARTED = 'article_generation_started';
    public const ARTICLE_COMPLETED = 'article_generated';
    public const ARTICLE_EDITED = 'article_edited';
    public const WORDPRESS_CONNECTED = 'wordpress_connected';
    public const PUBLISH_ATTEMPTED = 'publish_attempted';
    public const PUBLISH_SUCCEEDED = 'publish_succeeded';
    public const PUBLISH_FAILED = 'publish_failed';
    public const PRICING_VIEWED = 'pricing_viewed';
    public const PLAN_SELECTED = 'plan_selected';
    public const CHECKOUT_STARTED = 'checkout_started';
    public const PAYMENT_INTENT = 'payment_intent_created';
    public const PAYMENT_SUCCEEDED = 'payment_succeeded';
    public const PAYMENT_FAILED = 'payment_failed';
    public const SUBSCRIPTION_CREATED = 'subscription_created';
    public const TOKEN_USED = 'token_used';
    public const AGENT_REQUEST = 'agent_request';
    public const PAGE_VIEWED = 'page_viewed';
    public const SESSION_STARTED = 'session_started';
    public const SESSION_HEARTBEAT = 'session_heartbeat';
    public const SESSION_ENDED = 'session_ended';
    public const FEATURE_USED = 'feature_used';

    public static function all(): array
    {
        return [
            self::SIGNUP, self::OTP_REQUESTED, self::OTP_VERIFIED, self::LOGIN_SUCCESS, self::LOGIN_FAILED,
            self::SITE_ADDED, self::SITE_ANALYZED, self::KEYWORD_ANALYSIS, self::CONTENT_RESEARCH_COMPLETED,
            self::IDEA_CREATED, self::IDEA_SCORED, self::ARTICLE_STARTED, self::ARTICLE_COMPLETED,
            self::ARTICLE_EDITED, self::WORDPRESS_CONNECTED, self::PUBLISH_ATTEMPTED, self::PUBLISH_SUCCEEDED,
            self::PUBLISH_FAILED, self::PRICING_VIEWED, self::PLAN_SELECTED, self::CHECKOUT_STARTED,
            self::PAYMENT_INTENT, self::PAYMENT_SUCCEEDED, self::PAYMENT_FAILED, self::SUBSCRIPTION_CREATED,
            self::TOKEN_USED, self::AGENT_REQUEST, self::PAGE_VIEWED, self::SESSION_STARTED,
            self::SESSION_HEARTBEAT, self::SESSION_ENDED, self::FEATURE_USED,
        ];
    }

    public static function meaningful(): array
    {
        return [
            self::SITE_ADDED, self::SITE_ANALYZED, self::KEYWORD_ANALYSIS, self::CONTENT_RESEARCH_COMPLETED,
            self::IDEA_CREATED, self::IDEA_SCORED, self::ARTICLE_STARTED, self::ARTICLE_COMPLETED,
            self::ARTICLE_EDITED, self::WORDPRESS_CONNECTED, self::PUBLISH_ATTEMPTED, self::PUBLISH_SUCCEEDED,
            self::PAYMENT_INTENT, self::PAYMENT_SUCCEEDED, self::SUBSCRIPTION_CREATED, self::FEATURE_USED,
        ];
    }

    public static function stageDefinitions(): array
    {
        return [
            'signup' => ['label' => 'Sign-up', 'events' => [self::SIGNUP], 'required' => true],
            'first_login' => ['label' => 'Authentication / first login', 'events' => [self::LOGIN_SUCCESS, self::OTP_VERIFIED], 'required' => true],
            'domain_added' => ['label' => 'Domain/site added', 'events' => [self::SITE_ADDED], 'required' => true],
            'site_analyzed' => ['label' => 'Site analysis completed', 'events' => [self::SITE_ANALYZED], 'required' => true],
            'first_value' => ['label' => 'First product value', 'events' => [self::CONTENT_RESEARCH_COMPLETED, self::IDEA_CREATED, self::ARTICLE_COMPLETED], 'required' => true],
            'article_generated' => ['label' => 'Article generation', 'events' => [self::ARTICLE_COMPLETED], 'required' => true],
            'pricing_viewed' => ['label' => 'Pricing viewed', 'events' => [self::PRICING_VIEWED], 'required' => false],
            'checkout_started' => ['label' => 'Checkout started', 'events' => [self::CHECKOUT_STARTED], 'required' => false],
            'payment_intent' => ['label' => 'Payment request created', 'events' => [self::PAYMENT_INTENT], 'required' => true],
            'purchase' => ['label' => 'Successful purchase', 'events' => [self::PAYMENT_SUCCEEDED], 'required' => true],
        ];
    }

    public static function isAllowed(string $event): bool
    {
        return in_array($event, self::all(), true);
    }
}
