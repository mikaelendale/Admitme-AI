# AdmitMe: User Flow and UI Pages Todo List

This document outlines the proposed user flow and a detailed list of UI pages required for the AdmitMe MVP, based on the provided feature set and completed work.

## 1. User Flow

The AdmitMe user journey is designed to guide students through profile creation, college selection, gap analysis, essay writing, and continuous mentorship. The flow emphasizes a clear path from initial engagement to leveraging core features for college admissions success.

### 1.1. Onboarding & Profile Setup

1.  **Sign Up / Login**: Users create an account or log in. (Already completed: User authentication, Onboarding flow + $1 trial).
2.  **Initial Profile Intake**: Users are prompted to provide initial profile information, potentially by pasting free-form text. The AI processes this data.
3.  **Profile Overview**: Display of the structured profile, including a profile completeness score (0-100), GPA normalizer results, spike detection, and academic trajectory analysis.

### 1.2. Core Feature Engagement

1.  **Dashboard / Home**: A central hub providing an overview of the user's progress, quick access to main features, and proactive nudges from the AI Mentor.
2.  **College List Builder**: Users navigate to this section to generate and refine their personalized college list. They can view details for each school, including fit scores, deadlines, and financial aid estimates.
3.  **Gap + Fix Engine**: Users access this to understand their rejection risks for specific schools and receive a prioritized fix roadmap with actionable steps and a what-if simulator.
4.  **Essay Suite**: This section allows users to brainstorm essay topics, utilize a draft assistant, receive critiques (essay roast, cliché detector), generate 
first drafts for 
“Why This School” essays, and manage all supplemental essays.
5.  **AI Mentor Chat**: An always-on conversational interface for personalized advice and proactive nudges.

### 1.3. Account Management & Billing

1.  **Settings Page**: Users can manage billing, preferences, and delete their account. (Already completed: Settings page (billing, preferences, delete account)).
2.  **Subscription Management**: Users can view their current plan and upgrade/downgrade their subscription. (Already completed: Payment (Stripe, Sprint, Pro, Boost)).

## 2. UI Pages Todo List

This section details the UI pages required for the AdmitMe MVP, categorized by feature area. The list includes both new pages to be developed and existing pages that may require enhancements.

### 2.1. Core Platform & Onboarding

| Page Name | Description | Status | Notes |
|---|---|---|---|
| **Sign Up / Login** | User authentication forms | ✅ Completed | |
| **Onboarding Flow** | Initial guided setup for new users | ✅ Completed | |
| **Dashboard / Home** | Central hub with personalized overview, quick links to features, and AI Mentor summary. | ✅ Completed | Real hub stats, Reminders/nudges, mentor chat, workspace links, chat history URLs. |
| **Profile Setup (Initial)** | Page for users to input free-form profile data. | ✅ Completed | Onboarding flow + profile completion / intake paths. |
| **Profile Overview** | Displays structured profile data, scores, and analyses. | ✅ Completed | |
| **Settings (Billing, Preferences, Delete Account)** | User account management. | ✅ Completed | |
| **Subscription Management** | View and modify subscription plans. | ✅ Completed | |

### 2.2. Smart Profile Engine

| Page Name | Description | Status | Notes |
|---|---|---|---|
| **Profile Intake (Detailed)** | Allows users to refine or add more structured data to their profile. | ✅ Completed | Profile Overview + completion flows. |
| **GPA Normalizer Display** | Shows normalized GPA and explanation. | ✅ Completed | Profile Overview. |
| **Spike Detector Display** | Visualizes student's standout themes. | ✅ Completed | Profile Overview. |
| **Academic Trajectory Analysis Display** | Graphs or summaries of academic performance trends. | ✅ Completed | Profile Overview. |

### 2.3. College List Builder

| Page Name | Description | Status | Notes |
|---|---|---|---|
| **College List Generator** | Interface for generating an initial personalized college list. | ✅ Completed | Council / mentor-driven builds on College list builder. |
| **College List Display** | Shows the generated list with reach/target/safety categorization. | ✅ Completed | Table + cards + deadlines tab. |
| **School Detail Page** | Detailed view for each college, including 
fit scores, "Why this school" explanation, deadlines, and financial aid estimates. | ✅ Completed | Dedicated `/college-lists/{id}` page. |
| **Deadline Tracker** | Calendar view or list of all application deadlines. | ✅ Completed | Deadlines tab on college list builder. |
| **List Balancer Warning** | Alerts if the college list is unbalanced. | ✅ Completed | List balancer panel on college list builder. |
| **Financial Aid Estimator Display** | Shows estimated net cost. | ✅ Completed | School detail + list columns. |

### 2.4. Gap + Fix Engine

| Page Name | Description | Status | Notes |
|---|---|---|---|
| **Rejection Risk Analysis** | Displays top rejection reasons for selected schools. | ✅ Completed | Fully integrated via GapFixController and GapFixAnalysisService. |
| **Gap Scoring & Fix Roadmap** | Presents prioritized gaps and step-by-step action plans. | ✅ Completed | Available in Gap Fix view. |
| **What-If Simulator** | Interactive tool to explore how changes impact college chances. | ⬜ To Do | |

### 2.5. Essay Suite

| Page Name | Description | Status | Notes |
|---|---|---|---|
| **Essay Brainstormer** | Generates profile-specific essay angles. | ✅ Completed | Functional in EssaySuite via Brainstorm jobs. |
| **Draft Assistant Interface** | AI writing partner for essay drafting. | ✅ Completed | Integrated with Draft generation tool. |
| **Essay Critique (Roast, Cliché Detector)** | Provides honest feedback on essays. | ✅ Completed | Powered by EssayRoastService. |
| **"Why This School" Generator** | Generates first drafts for school-specific essays. | ✅ Completed | Automated generation working for single schools. |
| **Supplemental Essay Manager** | Tracks and manages all supplemental essays. | ✅ Completed | Controlled via Essay Suite and Essay Runs. |
| **Essay Strength Score Display** | Shows scores for Authenticity, Specificity, Impact, Structure, Differentiation. | ✅ Completed | Included in the Essay Roast payload. |

### 2.6. AI Mentor

| Page Name | Description | Status | Notes |
|---|---|---|---|
| **AI Mentor Chat Interface** | Conversational interface for personalized advice. | ✅ Completed | Dashboard mentor chat + streaming API. |
| **Proactive Nudges Display** | Shows important alerts and reminders. | ✅ Completed | Dashboard “Reminders” + hub stats pipeline. |
| **Tone Settings** | Allows users to adjust the AI Mentor's tone. | ✅ Completed | Settings → AI preferences (`mentor_tone`, essay style). |

## 3. Revenue Model & Add-ons

| Page Name | Description | Status | Notes |
|---|---|---|---|
| **Pricing Page** | Displays different subscription tiers and their features. | ⬜ To Do | This is a crucial page for conversion. |
| **Add-on Purchase Page** | Allows users to purchase one-time add-ons like human essay review or counselor sessions. | ✅ Completed | Settings → Add-ons (`/settings/add-ons`). |

## 4. Landing Page

| Page Name | Description | Status | Notes |
|---|---|---|---|
| **Landing Page (Hero, Stats, How It Works)** | Public-facing page to attract new users. | ✅ Completed | |

## 5. Completed Backend/API Components (for reference)

*   AI Context JSON with capping
*   NoCap agent (structured output for profile summary)
*   Database schema (profiles, sessions, responses, facts)
*   Dashboard hub stats & nudges (`DashboardHubInsights`, college list + deadlines + conversations)
*   College list council runs, school detail route, list balancer / deadline data on list page

This document provides a foundational roadmap for the UI development of AdmitMe. Further detailed wireframes and mockups will build upon this structure.
