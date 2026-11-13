.
├── app
│   ├── Actions
│   │   ├── Users
│   │   │   ├── BanUserAction.php
│   │   │   ├── VerifyPhotoAction.php
│   │   │   └── DeleteUserAction.php
│   │   ├── Matches
│   │   │   └── CreateMatchAction.php
│   │   ├── Payments
│   │   │   ├── ProcessPaymentAction.php
│   │   │   └── RefundPaymentAction.php
│   │   └── Moderation
│   │       ├── ApprovePhotoAction.php
│   │       └── RejectPhotoAction.php
│   │
│   ├── Console
│   │   └── Commands
│   │       ├── ClearExpiredTokensCommand.php
│   │       ├── ProcessDailyLikesCommand.php
│   │       └── SendSubscriptionRemindersCommand.php
│   │
│   ├── DTO
│   │   ├── ContentFilterResult.php
│   │   ├── UserRegistrationDTO.php
│   │   ├── MatchCreationDTO.php
│   │   └── PaymentProcessingDTO.php
│   │
│   ├── Enums
│   │   ├── BanReason.php
│   │   ├── CommentRejectReason.php
│   │   ├── DatingGoal.php
│   │   ├── DeletionReason.php
│   │   ├── DiaryRejectReason.php
│   │   ├── DiaryStatus.php
│   │   ├── FraudAlertSeverity.php
│   │   ├── FraudAlertStatus.php
│   │   ├── FraudTriggerType.php
│   │   ├── GiftCategory.php
│   │   ├── MatchStatus.php
│   │   ├── MediaCollection.php
│   │   ├── PhotoRejectReason.php
│   │   ├── RefundReason.php
│   │   ├── ReportReason.php
│   │   ├── ReportResolution.php
│   │   ├── StopWordAction.php
│   │   ├── StopWordCategory.php
│   │   ├── SwipeType.php
│   │   ├── UserBlockReason.php
│   │   └── UserEventType.php
│   │
│   ├── Events
│   │   ├── BroadcastStatusUpdated.php
│   │   ├── ChatMessageReceived.php
│   │   ├── NewMatch.php
│   │   ├── NewMessage.php
│   │   ├── SupportChatArchived.php
│   │   ├── SupportChatRead.php
│   │   ├── SupportChatUnarchived.php
│   │   ├── SupportMessageReceived.php
│   │   ├── TransactionRefunded.php
│   │   ├── TransactionRefundFailed.php
│   │   └── TransactionSynced.php
│   │
│   ├── Http
│   │   ├── Controllers
│   │   │   └── Api
│   │   │       └── V1
│   │   │           ├── Admin
│   │   │           │   ├── AdminUserController.php
│   │   │           │   ├── AdminReportController.php
│   │   │           │   ├── AdminModerationController.php
│   │   │           │   ├── AdminSettingsController.php
│   │   │           │   └── AdminDashboardController.php
│   │   │           ├── Web
│   │   │           │   ├── WebProfileController.php
│   │   │           │   ├── WebMatchController.php
│   │   │           │   ├── WebChatController.php
│   │   │           │   └── WebPaymentController.php
│   │   │           └── Mobile
│   │   │               ├── MobileAuthController.php
│   │   │               ├── MobileProfileController.php
│   │   │               ├── MobileSwipeController.php
│   │   │               ├── MobileChatController.php
│   │   │               └── MobilePaymentController.php
│   │   │
│   │   ├── Middleware
│   │   │   ├── Admin
│   │   │   │   ├── EnsureUserIsAdmin.php
│   │   │   │   └── LogAdminAction.php
│   │   │   └── Api
│   │   │       ├── ForceJsonResponse.php
│   │   │       └── CheckUserStatus.php
│   │   │
│   │   ├── Requests
│   │   │   └── Api
│   │   │       └── V1
│   │   │           ├── Admin
│   │   │           │   ├── AdminBanUserRequest.php
│   │   │           │   ├── AdminUpdateSettingsRequest.php
│   │   │           │   └── AdminModeratePhotoRequest.php
│   │   │           ├── Web
│   │   │           │   ├── WebUpdateProfileRequest.php
│   │   │           │   ├── WebCreatePaymentRequest.php
│   │   │           │   └── WebSendMessageRequest.php
│   │   │           └── Mobile
│   │   │               ├── MobileRegisterRequest.php
│   │   │               ├── MobileSwipeRequest.php
│   │   │               ├── MobileUploadPhotoRequest.php
│   │   │               └── MobileSendMessageRequest.php
│   │   │
│   │   └── Resources
│   │       └── Api
│   │           └── V1
│   │               ├── Admin
│   │               │   ├── AdminUserResource.php
│   │               │   ├── AdminReportResource.php
│   │               │   └── AdminTransactionResource.php
│   │               ├── Web
│   │               │   ├── WebProfileResource.php
│   │               │   ├── WebMatchResource.php
│   │               │   └── WebChatResource.php
│   │               └── Mobile
│   │                   ├── MobileUserResource.php
│   │                   ├── MobileSwipeResource.php
│   │                   └── MobileMessageResource.php
│   │
│   ├── Jobs
│   │   ├── Notifications
│   │   │   ├── SendPushNotificationJob.php
│   │   │   ├── SendSmsVerificationJob.php
│   │   │   ├── SendEmailNotificationJob.php
│   │   │   └── SendBroadcastNotificationJob.php
│   │   ├── Media
│   │   │   ├── ProcessUploadedPhotoJob.php
│   │   │   ├── GeneratePhotoThumbnailJob.php
│   │   │   └── ModeratePhotoJob.php
│   │   ├── Matches
│   │   │   ├── CalculateDailyLikesLimitJob.php
│   │   │   └── NotifyNewMatchJob.php
│   │   ├── Finance
│   │   │   ├── ProcessSubscriptionPaymentJob.php
│   │   │   ├── ProcessRefundJob.php
│   │   │   └── SyncTransactionJob.php
│   │   └── Users
│   │       ├── UpdateUserOnlineStatusJob.php
│   │       └── CleanExpiredProfilesJob.php
│   │
│   ├── Listeners
│   │   ├── Finance
│   │   │   ├── HandleTransactionRefunded.php
│   │   │   ├── HandleTransactionRefundFailed.php
│   │   │   └── HandleTransactionSynced.php
│   │   ├── Matches
│   │   │   ├── SendNewMatchNotification.php
│   │   │   └── CreateChatForMatch.php
│   │   ├── Chats
│   │   │   ├── BroadcastNewMessage.php
│   │   │   └── UpdateUnreadCounters.php
│   │   └── Moderation
│   │       ├── NotifyUserAboutModeration.php
│   │       └── UpdateUserProfileStatus.php
│   │
│   ├── Models
│   │   ├── AdminLog.php
│   │   ├── Album.php
│   │   ├── BlogCategory.php
│   │   ├── BlogPost.php
│   │   ├── Broadcast.php
│   │   ├── Chat.php
│   │   ├── ChatParticipant.php
│   │   ├── City.php
│   │   ├── Country.php
│   │   ├── Diary.php
│   │   ├── DiaryComment.php
│   │   ├── DiaryCommentLike.php
│   │   ├── DiaryLike.php
│   │   ├── DiaryRubric.php
│   │   ├── DiarySubscription.php
│   │   ├── FraudAlert.php
│   │   ├── GeoIPLocation.php
│   │   ├── GeoTranslation.php
│   │   ├── Gift.php
│   │   ├── Interest.php
│   │   ├── InterestCategory.php
│   │   ├── Media.php
│   │   ├── Message.php
│   │   ├── Page.php
│   │   ├── PersonalAccessToken.php
│   │   ├── Photo.php
│   │   ├── PhotoComment.php
│   │   ├── ProfileView.php
│   │   ├── PromoCode.php
│   │   ├── PromoCodeUsage.php
│   │   ├── Report.php
│   │   ├── Setting.php
│   │   ├── State.php
│   │   ├── StopWord.php
│   │   ├── SubscriptionPlan.php
│   │   ├── SupportTemplate.php
│   │   ├── SupportTemplateCategory.php
│   │   ├── Swipe.php
│   │   ├── Transaction.php
│   │   ├── User.php
│   │   ├── UserAuthLog.php
│   │   ├── UserBalance.php
│   │   ├── UserBlock.php
│   │   ├── UserBoost.php
│   │   ├── UserCard.php
│   │   ├── UserEvent.php
│   │   ├── UserFavorite.php
│   │   ├── UserGift.php
│   │   ├── UserMatch.php
│   │   ├── UserPreference.php
│   │   ├── UserProfile.php
│   │   ├── UserSubscription.php
│   │   └── Verification.php
│   │
│   ├── Notifications
│   │   ├── BroadcastNotification.php
│   │   ├── DiaryCommentModerated.php
│   │   ├── DiaryModerated.php
│   │   ├── NewMatchNotification.php
│   │   ├── NewMessageNotification.php
│   │   ├── PaymentFailed.php
│   │   ├── PaymentSuccessful.php
│   │   ├── PhotoCommentModerated.php
│   │   ├── PhotoModerated.php
│   │   ├── ProfileFieldCleared.php
│   │   ├── RefundProcessed.php
│   │   ├── ReportModerated.php
│   │   ├── SubscriptionExpiredNotification.php
│   │   ├── SubscriptionExpiringSoonNotification.php
│   │   ├── UserBanned.php
│   │   ├── UserDeleted.php
│   │   ├── UserWarned.php
│   │   └── VerifyEmail.php
│   │
│   ├── Observers
│   │   ├── UserObserver.php
│   │   ├── UserProfileObserver.php
│   │   ├── UserSubscriptionObserver.php
│   │   ├── PhotoObserver.php
│   │   ├── DiaryObserver.php
│   │   └── TransactionObserver.php
│   │
│   ├── Providers
│   │   ├── AppServiceProvider.php
│   │   ├── HorizonServiceProvider.php
│   │   └── RateLimitServiceProvider.php
│   │
│   ├── Services
│   │   ├── Users
│   │   │   ├── UserService.php
│   │   │   ├── UserSearchService.php
│   │   │   ├── ProfileViewService.php
│   │   │   └── UserOnlineService.php
│   │   ├── Matches
│   │   │   ├── MatchService.php
│   │   │   └── SwipeService.php
│   │   ├── Chats
│   │   │   ├── ChatService.php
│   │   │   └── UnreadCounterService.php
│   │   ├── Payments
│   │   │   ├── PaymentService.php
│   │   │   └── SubscriptionService.php
│   │   ├── Media
│   │   │   ├── MediaProcessorService.php
│   │   │   └── PhotoService.php
│   │   ├── Moderation
│   │   │   ├── ContentFilterService.php
│   │   │   └── ModerationService.php
│   │   ├── Admin
│   │   │   ├── AdminService.php
│   │   │   └── ReportService.php
│   │   └── Common
│   │       ├── CaptchaService.php
│   │       └── MockAcquiringService.php
│   │
│   └── Support
│       └── UserAgentParser.php
│
├── bootstrap
│   ├── cache
│   │   ├── .gitignore
│   │   ├── packages.php
│   │   └── services.php
│   ├── app.php
│   └── providers.php
│
├── config
│   ├── app.php
│   ├── auth.php
│   ├── broadcasting.php
│   ├── cache.php
│   ├── cors.php
│   ├── database.php
│   ├── filesystems.php
│   ├── geo_ip_extra_translations.php
│   ├── horizon.php
│   ├── logging.php
│   ├── mail.php
│   ├── media.php
│   ├── profile_fields.php
│   ├── purifier.php
│   ├── queue.php
│   ├── reverb.php
│   ├── sanctum.php
│   ├── search.php
│   ├── services.php
│   ├── session.php
│   └── settings.php
│
├── database
│   ├── factories
│   │   ├── PhotoFactory.php
│   │   ├── SubscriptionPlanFactory.php
│   │   └── UserFactory.php
│   ├── migrations
│   │   ├── 0001_01_01_000001_create_postgis_extension.php
│   │   ├── 0001_01_01_000002_create_countries_table.php
│   │   ├── 0001_01_01_000002_create_states_table.php
│   │   ├── 0001_01_01_000003_create_cities_table.php
│   │   ├── 0001_01_01_000003_create_geo_translations_table.php
│   │   ├── 0001_01_01_000004_create_users_table.php
│   │   ├── 0001_01_01_000005_create_user_profiles_table.php
│   │   ├── 0001_01_01_000006_create_user_preferences_table.php
│   │   ├── 0001_01_01_000007_create_user_balances_table.php
│   │   ├── 0001_01_01_000008_create_subscription_plans_table.php
│   │   ├── 0001_01_01_000009_create_transactions_table.php
│   │   ├── 0001_01_01_000010_create_gifts_table.php
│   │   ├── 0001_01_01_000011_create_user_subscriptions_table.php
│   │   ├── 0001_01_01_000012_create_albums_table.php
│   │   ├── 0001_01_01_000013_create_photos_table.php
│   │   ├── 0001_01_01_000013_create_user_cards_table.php
│   │   ├── 0001_01_01_000014_create_user_blocks_table.php
│   │   ├── 0001_01_01_000014_create_user_boosts_table.php
│   │   ├── 0001_01_01_000015_create_user_favorites_table.php
│   │   ├── 0001_01_01_000016_create_user_auth_logs_table.php
│   │   ├── 0001_01_01_000017_create_user_events_table.php
│   │   ├── 0001_01_01_000018_create_personal_access_tokens_table.php
│   │   ├── 0001_01_01_000021_create_cache_table.php
│   │   ├── 0001_01_01_000021_create_notifications_table.php
│   │   ├── 0001_01_01_000022_create_jobs_table.php
│   │   ├── 0001_01_01_000025_create_photo_comments_table.php
│   │   ├── 0001_01_01_000026_create_swipes_table.php
│   │   ├── 0001_01_01_000027_create_user_matches_table.php
│   │   ├── 0001_01_01_000028_create_user_gifts_table.php
│   │   ├── 0001_01_01_000029_create_chats_table.php
│   │   ├── 0001_01_01_000030_create_chat_participants_table.php
│   │   ├── 0001_01_01_000031_create_messages_table.php
│   │   ├── 0001_01_01_000032_create_profile_views_table.php
│   │   ├── 0001_01_01_000033_create_reports_table.php
│   │   ├── 0001_01_01_000034_create_settings_table.php
│   │   ├── 0001_01_01_000035_create_broadcasts_table.php
│   │   ├── 0001_01_01_000036_create_admin_logs_table.php
│   │   ├── 0001_01_01_000037_create_fraud_alerts_table.php
│   │   ├── 0001_01_01_000038_create_stop_words_table.php
│   │   ├── 0001_01_01_000039_create_pages_table.php
│   │   ├── 0001_01_01_000040_create_verifications_table.php
│   │   ├── 0001_01_01_000041_create_promo_codes_table.php
│   │   ├── 0001_01_01_000042_create_promo_code_usages_table.php
│   │   ├── 0001_01_01_000043_create_diary_rubrics_table.php
│   │   ├── 0001_01_01_000044_create_diaries_table.php
│   │   ├── 0001_01_01_000045_create_diary_comments_table.php
│   │   ├── 0001_01_01_000046_create_diary_subscriptions_table.php
│   │   ├── 0001_01_01_000047_create_diary_likes_table.php
│   │   ├── 0001_01_01_000048_create_diary_comment_likes_table.php
│   │   ├── 0001_01_01_000049_create_media_table.php
│   │   ├── 0001_01_01_000050_create_blog_categories_table.php
│   │   ├── 0001_01_01_000051_create_blog_posts_table.php
│   │   ├── 0001_01_01_000052_create_support_template_categories_table.php
│   │   ├── 0001_01_01_000053_create_support_templates_table.php
│   │   ├── 0001_01_01_000054_create_geoip_locations_table.php
│   │   ├── 2025_01_01_000001_create_interest_categories_table.php
│   │   ├── 2025_01_01_000002_create_interests_table.php
│   │   ├── 2025_01_01_000003_create_user_interests_table.php
│   │   ├── 2025_01_01_000005_create_interest_triggers.php
│   │   ├── 2026_10_28_150427_add_meta_title_to_pages_table.php
│   │   ├── 2026_11_08_020000_add_staff_state_to_chats_table.php
│   │   ├── 2026_11_08_030000_add_stop_words_category_action_index.php
│   │   ├── 2026_11_11_000000_refactor_photo_comments_moderation_strategy.php
│   │   ├── 2026_11_11_010000_refactor_photo_reject_reasons.php
│   │   ├── 2026_11_11_020000_refactor_diary_comments_moderation_strategy.php
│   │   └── 2026_11_11_030000_add_moderation_fields_to_diaries_table.php
│   ├── seeders
│   │   ├── AdminLogSeeder.php
│   │   ├── AdminSeeder.php
│   │   ├── BlogSeeder.php
│   │   ├── BroadcastSeeder.php
│   │   ├── ChatSeeder.php
│   │   ├── DatabaseSeeder.php
│   │   ├── DiaryModuleSeeder.php
│   │   ├── FinanceHistorySeeder.php
│   │   ├── FraudAlertSeeder.php
│   │   ├── GeoIPLocationsSeeder.php
│   │   ├── GiftSeeder.php
│   │   ├── InterestSeeder.php
│   │   ├── PageSeeder.php
│   │   ├── PhotoAlbumsSeeder.php
│   │   ├── PhotoCommentSeeder.php
│   │   ├── ProfileViewSeeder.php
│   │   ├── PromoCodeSeeder.php
│   │   ├── PromoCodeUsagesSeeder.php
│   │   ├── ReportSeeder.php
│   │   ├── SettingSeeder.php
│   │   ├── StaffSeeder.php
│   │   ├── StopWordSeeder.php
│   │   ├── SubscriptionPlanSeeder.php
│   │   ├── SupportTemplateSeeder.php
│   │   ├── SwipeSeeder.php
│   │   ├── TestLogsSeeder.php
│   │   ├── UserAuthLogsSeeder.php
│   │   ├── UserBlockSeeder.php
│   │   ├── UserCardsSeeder.php
│   │   ├── UserEventsSeeder.php
│   │   ├── UserInterestsSeeder.php
│   │   ├── UserSeeder.php
│   │   └── VerificationSeeder.php
│   ├── database.sqlite
│   └── .gitignore
│
├── docs
│   ├── environment
│   │   ├── PART-1.env и окружение.md
│   │   └── storage-scheme.txt
│   ├── geo
│   │   └── geonames.md
│   ├── services
│   │   ├── horizon.md
│   │   ├── mailpit.md
│   │   ├── pulse.md
│   │   ├── purifier.md
│   │   ├── redis.md
│   │   └── reverb.md
│   └── README.md
│
├── lang
│   ├── en
│   │   ├── auth.php
│   │   ├── pagination.php
│   │   ├── passwords.php
│   │   └── validation.php
│   └── ru
│       ├── auth.php
│       ├── pagination.php
│       ├── passwords.php
│       └── validation.php
│
├── public
│   ├── storage -> /home/webpolka/projects/dating-api/storage/app/public
│   ├── favicon.ico
│   ├── .htaccess
│   ├── index.php
│   └── robots.txt
│
├── resources
│   ├── css
│   │   └── app.css
│   ├── js
│   │   └── app.js
│   └── views
│       └── welcome.blade.php
│
├── routes
│   ├── admin.php
│   ├── api.php
│   ├── channels.php
│   ├── console.php
│   └── web.php
│
├── storage
│   ├── app
│   │   ├── private
│   │   ├── public
│   │   └── .gitignore
│   ├── framework
│   │   ├── cache
│   │   ├── sessions
│   │   ├── testing
│   │   ├── views
│   │   └── .gitignore
│   └── logs
│       ├── laravel-2026-11-13.log
│       └── laravel.log
│
├── tests
│   ├── Feature
│   │   ├── Admin
│   │   ├── Api
│   │   └── ExampleTest.php
│   ├── Unit
│   │   └── ExampleTest.php
│   └── TestCase.php
│
├── AGENTS.md
├── artisan
├── CLAUDE.md
├── composer.json
├── composer.lock
├── compose.yaml
├── .editorconfig
├── .env
├── .env.example
├── .gitattributes
├── .gitignore
├── .npmrc
├── package.json
├── package-lock.json
├── .phpstorm.meta.php
├── phpunit.xml
├── README.md
├── REFACTORING.md
└── vite.config.js