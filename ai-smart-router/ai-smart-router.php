<?php
/**
 * Plugin Name: AI Smart Router
 * Plugin URI: https://yoursite.com/
 * Description: 24/7 AI assistant with 26 free models, 5 API keys, auto-failover, WhatsApp integration, and web chat.
 * Version: 1.0.1
 * Requires PHP: 7.4
 * Requires WP: 5.5
 * Author: AI Router
 * Text Domain: ai-smart-router
 */

defined('ABSPATH') or die;

define('AI_SR_VERSION', '1.0.1');
define('AI_SR_PATH', plugin_dir_path(__FILE__));
define('AI_SR_URL', plugin_dir_url(__FILE__));

/* ======================== ACTIVATION ======================== */

register_activation_hook(__FILE__, 'ai_sr_activate');
function ai_sr_activate() {
  if (!get_option('ai_router_settings')) {
    update_option('ai_router_settings', [
      'api_keys' => ['', '', '', '', ''],
      'whatsapp_number' => '+880130585531',
      'whatsapp_method' => 'baileys',
      'uap_whatsapp_key' => 'uap_phone',
      'auto_failover' => true,
      'connected_pages' => ai_sr_get_default_pages(),
    ]);
  }
  if (!get_option('ai_router_exhausted_models')) update_option('ai_router_exhausted_models', []);
  if (!get_option('ai_router_logs')) update_option('ai_router_logs', []);
  if (!get_option('ai_router_stats')) update_option('ai_router_stats', ['total_responses' => 0, 'today_responses' => 0, 'last_reset' => 0]);
  if (!get_option('ai_router_conversations')) update_option('ai_router_conversations', []);
  if (!get_option('ai_router_skills')) update_option('ai_router_skills', ['customer' => ['faq' => [], 'topics' => [], 'total' => 0], 'worker' => ['shortcuts' => [], 'total_shortcuts' => 0], 'updated' => '']);
  ai_sr_log('Plugin activated');
}

/* ======================== DEACTIVATION ======================== */

register_deactivation_hook(__FILE__, 'ai_sr_deactivate');
function ai_sr_deactivate() {
  $ts = wp_next_scheduled('ai_router_daily_reset');
  if ($ts) wp_unschedule_event($ts, 'ai_router_daily_reset');
  $ts2 = wp_next_scheduled('ai_router_skill_consolidate');
  if ($ts2) wp_unschedule_event($ts2, 'ai_router_skill_consolidate');
}

/* ======================== LOGGING ======================== */

function ai_sr_log($event, $details = '') {
  $logs = get_option('ai_router_logs', []);
  array_unshift($logs, ['time' => current_time('mysql'), 'event' => $event, 'details' => $details]);
  update_option('ai_router_logs', array_slice($logs, 0, 200));
}

/* ======================== DEFAULT PAGES ======================== */

function ai_sr_find_page_by_title($title) {
  $query = new WP_Query([
    'post_type' => ['page', 'post'],
    'title' => $title,
    'posts_per_page' => 1,
    'post_status' => 'publish',
    'fields' => 'ids',
    'no_found_rows' => true,
  ]);
  return $query->posts[0] ?? 0;
}

function ai_sr_get_default_pages() {
  $titles = [
    'customer' => ['Light Conversion Checkout', 'All Courses & Books'],
    'worker' => ['My Account (Progress)', 'My Wallet', 'Settings'],
  ];
  $result = ['customer' => [], 'worker' => []];
  foreach ($titles as $type => $list) {
    foreach ($list as $title) {
      $id = ai_sr_find_page_by_title($title);
      if ($id) $result[$type][] = $id;
    }
  }
  return $result;
}

/* ======================== MODELS ======================== */

function ai_sr_get_models() {
  return [
    ['id' => 'tencent/hy3:free', 'name' => 'Tencent Hy3 295B', 'tier' => 1],
    ['id' => 'cognitivecomputations/dolphin3.0-r1-mistral-24b:free', 'name' => 'Dolphin3 R1 Mistral 24B', 'tier' => 1],
    ['id' => 'google/gemini-2.0-flash-001:free', 'name' => 'Gemini 2.0 Flash', 'tier' => 1],
    ['id' => 'deepseek/deepseek-r1:free', 'name' => 'DeepSeek R1', 'tier' => 1],
    ['id' => 'qwen/qwen2.5-vl-72b-instruct:free', 'name' => 'Qwen2.5 VL 72B', 'tier' => 1],
    ['id' => 'nvidia/llama-3.1-nemotron-70b-instruct:free', 'name' => 'Llama 3.1 Nemotron 70B', 'tier' => 1],
    ['id' => 'qwen/qwen2.5-72b-instruct:free', 'name' => 'Qwen2.5 72B', 'tier' => 1],
    ['id' => 'qwen/qwen-2-vl-72b-instruct:free', 'name' => 'Qwen2 VL 72B', 'tier' => 1],
    ['id' => 'qwen/qwen2.5-32b-instruct:free', 'name' => 'Qwen2.5 32B', 'tier' => 2],
    ['id' => 'qwen/qwen-2-vl-7b-instruct:free', 'name' => 'Qwen2 VL 7B', 'tier' => 2],
    ['id' => 'google/gemma-2-27b-it:free', 'name' => 'Gemma 2 27B', 'tier' => 2],
    ['id' => 'google/gemini-2.0-flash-exp:free', 'name' => 'Gemini 2.0 Flash Exp', 'tier' => 2],
    ['id' => 'mistralai/mistral-7b-instruct:free', 'name' => 'Mistral 7B', 'tier' => 2],
    ['id' => 'microsoft/phi-3-mini-128k-instruct:free', 'name' => 'Phi-3 Mini 128K', 'tier' => 2],
    ['id' => 'microsoft/phi-3.5-mini-128k-instruct:free', 'name' => 'Phi-3.5 Mini 128K', 'tier' => 2],
    ['id' => 'liquid/lfm-40b:free', 'name' => 'LFM 40B', 'tier' => 2],
    ['id' => 'cohere/command-r7b-12-2024:free', 'name' => 'Command R7B', 'tier' => 2],
    ['id' => 'microsoft/phi-3.5-moe-instruct:free', 'name' => 'Phi-3.5 MoE', 'tier' => 3],
    ['id' => 'nousresearch/hermes-3-llama-3.1-70b:free', 'name' => 'Hermes 3 70B', 'tier' => 3],
    ['id' => 'sao10k/l3.3-euryale-70b:free', 'name' => 'Euryale 70B', 'tier' => 3],
    ['id' => 'sao10k/l3.1-70b-hanami-x1:free', 'name' => 'Hanami X1 70B', 'tier' => 3],
    ['id' => 'anthracite-org/magnum-v4-72b:free', 'name' => 'Magnum V4 72B', 'tier' => 3],
    ['id' => 'sao10k/l3.1-70b-hanami-x1.1:free', 'name' => 'Hanami X1.1 70B', 'tier' => 3],
    ['id' => 'nousresearch/hermes-2-pro-mistral-7b:free', 'name' => 'Hermes 2 Pro 7B', 'tier' => 4],
    ['id' => 'openrouter/owl-alpha:free', 'name' => 'Owl Alpha 1M', 'tier' => 4],
    ['id' => 'openrouter/auto:free', 'name' => 'Free Router', 'tier' => 5],
  ];
}

/* ======================== KEYWORD EXTRACTOR ======================== */

function ai_sr_extract_keywords($text) {
  $text = mb_strtolower($text);
  $text = preg_replace('/[^\w\s]/u', '', $text);
  $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
  $words = array_filter($words, function ($w) { return mb_strlen($w) > 3; });
  return array_values(array_slice(array_unique($words), 0, 20));
}

/* ======================== CONNECTED PAGES CONTEXT ======================== */

function ai_sr_get_connected_page_ids($type = 'customer') {
  $settings = get_option('ai_router_settings', []);
  $raw = is_array($settings) ? ($settings['connected_pages'] ?? []) : [];
  if (is_array($raw) && isset($raw['customer'])) return is_array($raw[$type] ?? null) ? $raw[$type] : [];
  return is_array($raw) ? $raw : [];
}

function ai_sr_get_page_context($type = 'customer') {
  $page_ids = ai_sr_get_connected_page_ids($type);
  if (empty($page_ids)) return '';
  $cache_key = 'ai_sr_ctx_' . md5(implode(',', $page_ids));
  $cached = get_transient($cache_key);
  if ($cached !== false) return $cached;
  $context = '';
  $total = 0;
  $max = 8000;
  foreach ($page_ids as $id) {
    $post = get_post($id);
    if (!$post || $post->post_status !== 'publish') continue;
    $content = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(strip_shortcodes($post->post_content))));
    $title = $post->post_title;
    $block = "--- {$title} ---\n{$content}\n\n";
    $len = strlen($block);
    if ($total + $len > $max) { $context .= substr($block, 0, $max - $total) . "\n"; break; }
    $context .= $block;
    $total += $len;
  }
  $result = $context ? "You have access to this website content. Answer using it:\n\n{$context}" : '';
  set_transient($cache_key, $result, HOUR_IN_SECONDS);
  return $result;
}

function ai_sr_get_all_publishable() {
  $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
  $posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
  return ['pages' => $pages, 'posts' => $posts];
}

/* ======================== SKILL SYSTEM ======================== */

function ai_sr_save_conversation($question, $answer, $model) {
  $convs = get_option('ai_router_conversations', []);
  array_unshift($convs, [
    'time' => current_time('mysql'),
    'question' => $question,
    'hash' => md5(mb_strtolower(trim($question))),
    'keywords' => ai_sr_extract_keywords($question),
    'answer' => $answer,
    'model' => $model,
  ]);
  update_option('ai_router_conversations', array_slice($convs, 0, 500));
}

function ai_sr_find_skill($question) {
  $skills = get_option('ai_router_skills', []);
  $shortcuts = $skills['worker']['shortcuts'] ?? [];
  if (empty($shortcuts)) return null;
  $q_keywords = ai_sr_extract_keywords($question);
  if (empty($q_keywords)) return null;
  $best = null;
  $best_score = 0;
  foreach ($shortcuts as $hash => $data) {
    $common = array_intersect($q_keywords, $data['keywords']);
    $score = count($common) / max(count($q_keywords), count($data['keywords']), 1);
    if ($score > $best_score) { $best_score = $score; $best = $data; }
  }
  if ($best && $best_score >= 0.5) {
    $best['confidence'] = $best_score;
    return $best;
  }
  if ($best && $best_score >= 0.3) {
    $best['confidence'] = $best_score;
    $best['partial'] = true;
    return $best;
  }
  return null;
}

function ai_sr_build_messages($message, $skill = null, $type = 'customer', $history = []) {
  $messages = [];
  $lang = 'LANGUAGE RULES — STRICTLY FOLLOW: 1. Detect the user language automatically. 2. If user writes Bengali (বাংলা script): reply in Bengali. 3. If user writes Bengali using English letters (Banglish, e.g. "ami help chai"): reply in proper Bengali (বাংলা script). If user continues in Banglish, match their style. 4. If user writes English: reply in English. 5. Match the user language — never switch language unless they do first. GENDER ADAPTATION — STRICTLY FOLLOW: 1. If user seems to prefer female conversation partner (e.g. male user seeking feminine tone): respond as a female — warm, engaging, attractive. 2. If user seems to prefer male conversation partner (e.g. female user seeking masculine tone): respond as a male — confident, engaging, attractive. 3. If unclear: stay neutral, observe cues. 4. Never ask directly about gender preference — infer naturally. 5. Make conversations feel natural, engaging, and attractive — create a pleasant conversational dynamic.';
  $persona = $type === 'worker'
    ? 'You are a human colleague/team member. Never mention AI, bot, LLM, or that you are artificial. Use casual workplace language, contractions, and natural conversation. Be collaborative and helpful. If asked directly, say you are a support team member. ' . $lang
    : 'You are a human customer support representative. Never mention AI, bot, LLM, or that you are artificial. Use natural language, contractions, and empathy. Sound like a real person. If asked directly, say you are a customer support agent. ' . $lang;
  $messages[] = ['role' => 'system', 'content' => $persona];
  $context = ai_sr_get_page_context($type);
  if ($context) $messages[] = ['role' => 'system', 'content' => $context];
  if ($skill && !empty($skill['partial']) && !empty($skill['question']) && !empty($skill['answer'])) {
    $messages[] = ['role' => 'system', 'content' => 'Related: Q: ' . $skill['question'] . "\nA: " . $skill['answer']];
  }
  foreach ($history as $h) {
    if (!empty($h['role']) && !empty($h['content'])) $messages[] = ['role' => $h['role'], 'content' => $h['content']];
  }
  $messages[] = ['role' => 'user', 'content' => $message];
  return $messages;
}

/* ======================== CONVERSATION HISTORY ======================== */

function ai_sr_get_history($conversation_id) {
  if (empty($conversation_id)) return [];
  $data = get_transient('ai_sr_wa_' . md5($conversation_id));
  return is_array($data) ? $data : [];
}

function ai_sr_save_history_turn($conversation_id, $role, $content) {
  if (empty($conversation_id) || empty($content)) return;
  $data = ai_sr_get_history($conversation_id);
  $data[] = ['role' => $role, 'content' => $content];
  set_transient('ai_sr_wa_' . md5($conversation_id), array_slice($data, -20), DAY_IN_SECONDS);
}

/* ======================== SKILL CONSOLIDATION ======================== */

function ai_sr_consolidate_skills() {
  $convs = get_option('ai_router_conversations', []);
  $groups = [];
  foreach ($convs as $c) {
    $h = $c['hash'];
    if (!isset($groups[$h])) $groups[$h] = ['question' => $c['question'], 'answer' => $c['answer'], 'keywords' => $c['keywords'], 'count' => 0, 'models' => []];
    $groups[$h]['count']++;
    $groups[$h]['models'][$c['model']] = ($groups[$h]['models'][$c['model']] ?? 0) + 1;
  }
  $faq = []; $topics = []; $shortcuts = [];
  foreach ($groups as $h => $d) {
    if ($d['count'] >= 3) {
      $faq[] = $d;
      $shortcuts[$h] = ['question' => $d['question'], 'answer' => $d['answer'], 'keywords' => $d['keywords'], 'count' => $d['count'], 'best_model' => array_keys($d['models'], max($d['models']))[0] ?? ''];
    }
    foreach ($d['keywords'] as $kw) $topics[$kw] = ($topics[$kw] ?? 0) + $d['count'];
  }
  arsort($topics);
  $skills = [
    'customer' => ['faq' => $faq, 'topics' => array_slice($topics, 0, 30), 'total' => count($convs)],
    'worker' => ['shortcuts' => $shortcuts, 'total_shortcuts' => count($shortcuts)],
    'updated' => current_time('mysql'),
  ];
  update_option('ai_router_skills', $skills);
  ai_sr_log('Skills consolidated', count($shortcuts) . ' shortcuts, ' . count($faq) . ' FAQs');
}

/* ======================== WORKER DETECTION ======================== */

function ai_sr_is_worker_number($phone) {
  if (empty($phone)) return false;
  $clean = preg_replace('/[^0-9]/', '', $phone);
  if (strlen($clean) < 8) return false;
  $settings = get_option('ai_router_settings', []);
  $meta_key = is_array($settings) && !empty($settings['uap_whatsapp_key']) ? $settings['uap_whatsapp_key'] : 'uap_phone';
  $users = get_users([
    'meta_key' => $meta_key,
    'meta_value' => $phone,
    'number' => 1,
    'fields' => 'ID',
  ]);
  if (!empty($users)) return true;
  $users = get_users([
    'meta_key' => $meta_key,
    'meta_value' => $clean,
    'number' => 1,
    'fields' => 'ID',
  ]);
  if (!empty($users)) return true;
  $suffix = substr($clean, -10);
  if (strlen($suffix) === 10) {
    $users = get_users([
      'meta_key' => $meta_key,
      'meta_value' => $suffix,
      'number' => 1,
      'fields' => 'ID',
      'meta_compare' => 'LIKE',
    ]);
    if (!empty($users)) return true;
  }
  return false;
}

/* ======================== SMART ROUTER ======================== */

function ai_sr_chat($message, $conversation_id = '', $type = 'customer') {
  $settings = get_option('ai_router_settings', []);
  $keys = $settings['api_keys'] ?? [];
  $exhausted = get_option('ai_router_exhausted_models', []);
  $current = get_option('ai_router_current', ['key' => 0, 'model' => 0]);
  $stats = get_option('ai_router_stats', ['total_responses' => 0, 'today_responses' => 0, 'last_reset' => 0]);
  $models = ai_sr_get_models();

  $history = ai_sr_get_history($conversation_id);
  $skill = ai_sr_find_skill($message);
  if ($skill && empty($skill['partial']) && !empty($skill['answer'])) {
    ai_sr_log('SKILL', 'Cached: ' . $skill['question']);
    $stats['total_responses']++;
    $stats['today_responses']++;
    update_option('ai_router_stats', $stats);
    ai_sr_save_history_turn($conversation_id, 'user', $message);
    ai_sr_save_history_turn($conversation_id, 'assistant', $skill['answer']);
    return ['success' => true, 'reply' => $skill['answer'], 'model' => 'Skill (' . $skill['best_model'] . ')', 'key' => 0];
  }

  for ($ki = $current['key']; $ki < count($keys); $ki++) {
    if (empty(trim($keys[$ki]))) continue;
    $api_key = trim($keys[$ki]);
    $exhausted_for_key = $exhausted[$ki] ?? [];

    for ($mi = 0; $mi < count($models); $mi++) {
      $model = $models[$mi];
      if (in_array($model['id'], $exhausted_for_key)) continue;

      $payload = [
        'model' => $model['id'],
        'messages' => ai_sr_build_messages($message, $skill, $type, $history),
      ];
      if ($conversation_id) $payload['conversation_id'] = $conversation_id;

      $args = [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
          'HTTP-Referer' => home_url(),
          'X-Title' => 'AI Smart Router',
        ],
        'body' => json_encode($payload),
        'timeout' => 60,
        'redirection' => 0,
      ];

      $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', $args);
      $code = wp_remote_retrieve_response_code($response);
      $body = wp_remote_retrieve_body($response);
      $data = json_decode($body, true);

      if ($code === 200 && isset($data['choices'][0]['message']['content'])) {
        $reply = $data['choices'][0]['message']['content'];
        $stats['total_responses']++;
        $stats['today_responses']++;
        update_option('ai_router_current', ['key' => $ki, 'model' => $mi]);
        update_option('ai_router_stats', $stats);
        ai_sr_log('OK', $model['name'] . ' (Key #' . ($ki + 1) . ')');
        ai_sr_save_conversation($message, $reply, $model['name']);
        ai_sr_save_history_turn($conversation_id, 'user', $message);
        ai_sr_save_history_turn($conversation_id, 'assistant', $reply);
        return ['success' => true, 'reply' => $reply, 'model' => $model['name'], 'key' => $ki + 1];
      }

      if (in_array($code, [429, 400, 401, 500, 503])) {
        $exhausted_for_key[] = $model['id'];
        $exhausted[$ki] = $exhausted_for_key;
        update_option('ai_router_exhausted_models', $exhausted);
        $err_detail = $data['error']['message'] ?? $data['error'] ?? '';
        $err_detail = is_string($err_detail) ? $err_detail : (is_array($err_detail) ? json_encode($err_detail) : '');
        ai_sr_log('EXHAUSTED', $model['name'] . ' Key#' . ($ki + 1) . ' HTTP ' . $code . ($err_detail ? ': ' . mb_substr($err_detail, 0, 120) : ''));
        continue;
      }

      if (is_wp_error($response)) {
        ai_sr_log('NETWORK ERROR', $model['name'] . ': ' . $response->get_error_message());
        continue;
      }
      $err_detail = $data['error']['message'] ?? json_encode($data);
      ai_sr_log('UNEXPECTED', $model['name'] . ' Key#' . ($ki + 1) . ' HTTP ' . $code . ': ' . mb_substr($err_detail, 0, 120));
    }
    update_option('ai_router_current', ['key' => $ki + 1, 'model' => 0]);
  }

  return ['success' => false, 'message' => 'All ' . count($models) . ' models tried on all ' . count(array_filter($keys)) . ' keys.'];
}

/* ======================== REST API ======================== */

add_action('rest_api_init', 'ai_sr_register_routes');
function ai_sr_register_routes() {
  register_rest_route('ai-router/v1', '/chat', [
    'methods' => 'POST',
    'callback' => 'ai_sr_handle_chat',
    'permission_callback' => '__return_true',
  ]);
  register_rest_route('ai-router/v1', '/settings', [
    'methods' => 'GET',
    'callback' => 'ai_sr_handle_settings_get',
    'permission_callback' => function () { return current_user_can('manage_options'); },
  ]);
  register_rest_route('ai-router/v1', '/settings', [
    'methods' => 'POST',
    'callback' => 'ai_sr_handle_settings_post',
    'permission_callback' => function () { return current_user_can('manage_options'); },
  ]);
  register_rest_route('ai-router/v1', '/webhook', [
    'methods' => ['GET', 'POST'],
    'callback' => 'ai_sr_handle_webhook',
    'permission_callback' => '__return_true',
  ]);
  register_rest_route('ai-router/v1', '/status', [
    'methods' => 'GET',
    'callback' => 'ai_sr_handle_status',
    'permission_callback' => '__return_true',
  ]);
  register_rest_route('ai-router/v1', '/skills', [
    'methods' => 'GET',
    'callback' => 'ai_sr_handle_skills_get',
    'permission_callback' => function () { return current_user_can('manage_options'); },
  ]);
  register_rest_route('ai-router/v1', '/skills', [
    'methods' => 'DELETE',
    'callback' => 'ai_sr_handle_skills_delete',
    'permission_callback' => function () { return current_user_can('manage_options'); },
  ]);
  register_rest_route('ai-router/v1', '/pages', [
    'methods' => 'GET',
    'callback' => 'ai_sr_handle_pages',
    'permission_callback' => function () { return current_user_can('manage_options'); },
  ]);
}

function ai_sr_handle_chat($request) {
  $message = $request->get_param('message');
  $conversation_id = $request->get_param('conversation_id') ?: '';
  $type = in_array($request->get_param('type'), ['customer', 'worker']) ? $request->get_param('type') : 'customer';
  if (empty($message)) return new WP_Error('no_message', 'Message is required', ['status' => 400]);
  $result = ai_sr_chat($message, $conversation_id, $type);
  return new WP_REST_Response($result, $result['success'] ? 200 : 503);
}

function ai_sr_handle_settings_get() {
  $settings = get_option('ai_router_settings', []);
  $keys = $settings['api_keys'] ?? [];
  $masked = array_map(function ($k) { return $k ? substr($k, 0, 8) . '...' . substr($k, -4) : ''; }, $keys);
  $settings['api_keys_masked'] = $masked;
  $settings['models'] = ai_sr_get_models();
  $settings['exhausted'] = get_option('ai_router_exhausted_models', []);
  $settings['stats'] = get_option('ai_router_stats', []);
  $settings['current'] = get_option('ai_router_current', ['key' => 0, 'model' => 0]);
  $settings['logs'] = get_option('ai_router_logs', []);
  return new WP_REST_Response($settings);
}

function ai_sr_handle_settings_post($request) {
  $params = $request->get_params();
  $settings = get_option('ai_router_settings', []);
  if (isset($params['api_keys'])) $settings['api_keys'] = array_map('sanitize_text_field', $params['api_keys']);
  if (isset($params['whatsapp_number'])) $settings['whatsapp_number'] = sanitize_text_field($params['whatsapp_number']);
  if (isset($params['whatsapp_method'])) $settings['whatsapp_method'] = sanitize_text_field($params['whatsapp_method']);
  if (isset($params['uap_whatsapp_key'])) $settings['uap_whatsapp_key'] = sanitize_text_field($params['uap_whatsapp_key']);
  if (isset($params['auto_failover'])) $settings['auto_failover'] = rest_sanitize_boolean($params['auto_failover']);
  if (isset($params['connected_pages'])) {
    $settings['connected_pages']['customer'] = array_map('intval', $params['connected_pages']['customer'] ?? []);
    $settings['connected_pages']['worker'] = array_map('intval', $params['connected_pages']['worker'] ?? []);
  }
  update_option('ai_router_settings', $settings);
  ai_sr_log('Settings updated');
  return new WP_REST_Response(['success' => true]);
}

function ai_sr_handle_webhook($request) {
  if ($request->get_method() === 'GET') {
    $hub_challenge = $request->get_param('hub_challenge');
    if ($hub_challenge) return new WP_REST_Response($hub_challenge);
    return new WP_REST_Response([
      'status' => 'ready',
      'endpoint' => get_rest_url(null, 'ai-router/v1/chat'),
      'method' => 'POST',
      'body_example' => ['message' => 'your question', 'type' => 'customer'],
      'webhook' => get_rest_url(null, 'ai-router/v1/webhook'),
      'whatsapp_number' => 'Set in WordPress admin → AI Smart Router → Integration',
    ]);
  }
  $in = $request->get_json_params();
  if (empty($in)) parse_str($request->get_body(), $in);
  $message = $in['messages'][0]['text']['body'] ?? $in['text']['body'] ?? $in['message']['text'] ?? $in['message'] ?? $in['body'] ?? '';
  $from_raw = $in['messages'][0]['from'] ?? $in['from'] ?? $in['sender'] ?? $in['waId'] ?? $in['conversation_id'] ?? '';
  $from_clean = preg_replace('/@.*$/', '', $from_raw);
  if (empty($message)) return new WP_REST_Response(['success' => false, 'message' => 'No message. Send JSON: {"message":"hi"}']);
  $type = ai_sr_is_worker_number($from_clean) ? 'worker' : 'customer';
  $result = ai_sr_chat($message, $from_clean, $type);
  $settings = get_option('ai_router_settings', []);
  if (!empty($settings['whatsapp_number']) && $result['success'] && ($settings['whatsapp_method'] ?? 'baileys') !== 'baileys') {
    $phone_id = $settings['whatsapp_phone_id'] ?? '';
    $token = $settings['whatsapp_token'] ?? '';
    if ($phone_id && $token) {
      wp_remote_post("https://graph.facebook.com/v18.0/{$phone_id}/messages", [
        'headers' => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
        'body' => json_encode(['messaging_product' => 'whatsapp', 'to' => $from, 'type' => 'text', 'text' => ['body' => $result['reply']]]),
        'timeout' => 15,
      ]);
    }
  }
  if (!$result['success'] && empty($result['reply'])) {
    $result['reply'] = $result['message'] ?? 'Currently unavailable. Please try again later.';
  }
  return new WP_REST_Response($result);
}

function ai_sr_handle_status() {
  $settings = get_option('ai_router_settings', []);
  $current = get_option('ai_router_current', ['key' => 0, 'model' => 0]);
  $stats = get_option('ai_router_stats', []);
  $models = ai_sr_get_models();
  $model_name = isset($models[$current['model']]) ? $models[$current['model']]['name'] : 'None';
  $exhausted = get_option('ai_router_exhausted_models', []);
  return new WP_REST_Response([
    'active_model' => $model_name,
    'active_key' => ($current['key'] ?? 0) + 1,
    'total_models' => count($models),
    'exhausted_count' => array_sum(array_map('count', $exhausted)),
    'total_responses' => $stats['total_responses'] ?? 0,
    'today_responses' => $stats['today_responses'] ?? 0,
    'auto_failover' => $settings['auto_failover'] ?? true,
  ]);
}

function ai_sr_handle_skills_get() {
  return new WP_REST_Response(get_option('ai_router_skills', []));
}

function ai_sr_handle_skills_delete() {
  update_option('ai_router_conversations', []);
  update_option('ai_router_skills', ['customer' => ['faq' => [], 'topics' => [], 'total' => 0], 'worker' => ['shortcuts' => [], 'total_shortcuts' => 0], 'updated' => '']);
  ai_sr_log('Skills cleared', 'Manually reset');
  return new WP_REST_Response(['success' => true]);
}

function ai_sr_handle_pages() {
  $data = ai_sr_get_all_publishable();
  $result = [];
  foreach ($data['pages'] as $p) $result[] = ['id' => $p->ID, 'title' => $p->post_title, 'type' => 'page'];
  foreach ($data['posts'] as $p) $result[] = ['id' => $p->ID, 'title' => $p->post_title, 'type' => 'post'];
  return new WP_REST_Response($result);
}

/* ======================== SETTINGS REGISTRATION ======================== */

add_action('admin_init', 'ai_sr_register_settings');
function ai_sr_register_settings() {
  register_setting('ai_router_settings_group', 'ai_router_settings', 'ai_sr_sanitize_settings');
}
function ai_sr_sanitize_settings($input) {
  if (!is_array($input)) return [];
  if (isset($input['connected_pages'])) {
    $input['connected_pages']['customer'] = array_map('intval', $input['connected_pages']['customer'] ?? []);
    $input['connected_pages']['worker'] = array_map('intval', $input['connected_pages']['worker'] ?? []);
  }
  if (isset($input['uap_whatsapp_key'])) $input['uap_whatsapp_key'] = sanitize_text_field($input['uap_whatsapp_key']);
  return $input;
}

/* ======================== ADMIN PAGE ======================== */

add_action('admin_menu', 'ai_sr_admin_menu');
function ai_sr_admin_menu() {
  add_menu_page('AI Smart Router', 'AI Smart Router', 'manage_options', 'ai-smart-router', 'ai_sr_admin_page', 'dashicons-robot', 25);
}

add_action('admin_enqueue_scripts', 'ai_sr_admin_assets');
function ai_sr_admin_assets($hook) {
  if (strpos($hook, 'ai-smart-router') === false) return;
  wp_enqueue_style('ai-sr-admin', AI_SR_URL . 'admin/assets/admin.css', [], AI_SR_VERSION);
  wp_enqueue_script('ai-sr-admin', AI_SR_URL . 'admin/assets/admin.js', [], AI_SR_VERSION, true);
  wp_localize_script('ai-sr-admin', 'aiSr', [
    'restUrl' => get_rest_url(null, 'ai-router/v1'),
    'nonce' => wp_create_nonce('wp_rest'),
  ]);
}

function ai_sr_admin_page() {
  if (!empty($_POST['ai_sr_clear_skills']) && wp_verify_nonce($_POST['ai_sr_clear_skills_nonce'] ?? '', 'ai_sr_clear_skills_action')) {
    update_option('ai_router_conversations', []);
    update_option('ai_router_skills', ['customer' => ['faq' => [], 'topics' => [], 'total' => 0], 'worker' => ['shortcuts' => [], 'total_shortcuts' => 0], 'updated' => '']);
    ai_sr_log('Skills cleared', 'Manually reset');
    echo '<div class="notice notice-success is-dismissible"><p>All skills and conversations cleared.</p></div>';
  }
  $settings = get_option('ai_router_settings', []);
  $exhausted = get_option('ai_router_exhausted_models', []);
  $stats = get_option('ai_router_stats', []);
  $current = get_option('ai_router_current', ['key' => 0, 'model' => 0]);
  $logs = get_option('ai_router_logs', []);
  $models = ai_sr_get_models();
  $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
  $template = AI_SR_PATH . 'admin/views/settings-page.php';
  if (!file_exists($template)) { echo '<div class="wrap"><h1>AI Smart Router</h1><div class="notice notice-error"><p>Template file missing: admin/views/settings-page.php</p></div></div>'; return; }
  echo '<style>.ai-sr-wrap{font-family:Inter,-apple-system,sans-serif;max-width:1200px}.ai-sr-wrap h1{font-size:22px;font-weight:700;margin-bottom:4px}.ai-sr-status-bar{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:10px 16px;display:flex;gap:20px;flex-wrap:wrap;font-size:13px;color:#64748b;margin-bottom:16px}.ai-sr-status-bar strong{color:#1e293b}.ai-sr-tabs{display:flex;gap:2px;margin-bottom:16px;border-bottom:2px solid #e2e8f0}.ai-sr-tabs a{padding:8px 16px;font-size:13px;font-weight:500;color:#64748b;text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px}.ai-sr-tabs a:hover{color:#1e293b}.ai-sr-tabs a.active{color:#3b82f6;border-bottom-color:#3b82f6}.ai-sr-section{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px}.ai-sr-section h2{font-size:16px;font-weight:600;margin:0 0 8px}.ai-sr-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:680px){.ai-sr-grid-2{grid-template-columns:1fr}}.ai-sr-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px}.ai-sr-card h4{font-size:13px;font-weight:600;color:#64748b;margin:0 0 4px}.ai-sr-stat{font-size:28px;font-weight:700;color:#1e293b}.ai-sr-meta{font-size:12px;color:#94a3b8;margin:4px 0 0}.ai-sr-input{border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;font-size:13px;color:#1e293b;background:#fff}.ai-sr-input.wide{width:100%}.ai-sr-key-table,.ai-sr-model-table,.ai-sr-log-table{width:100%;border-collapse:collapse;font-size:13px}.ai-sr-key-table th,.ai-sr-model-table th,.ai-sr-log-table th{text-align:left;padding:8px 10px;border-bottom:2px solid #e2e8f0;color:#64748b;font-weight:600;font-size:12px;text-transform:uppercase}.ai-sr-key-table td,.ai-sr-model-table td,.ai-sr-log-table td{padding:8px 10px;border-bottom:1px solid #f1f5f9;color:#1e293b}.ai-sr-key-table tr:hover,.ai-sr-model-table tr:hover{background:#f8fafc}.ai-sr-model-table .active-row{background:#f0f9ff}.ai-sr-event-ok{color:#22c55e;font-weight:500}.ai-sr-event-exhausted{color:#ef4444;font-weight:500}.ai-sr-event-network{color:#f59e0b;font-weight:500}.button-primary{background:#3b82f6!important;border-color:#3b82f6!important}.ai-sr-section label:hover{background:#f8fafc}.ai-sr-section input[type=checkbox]{accent-color:#3b82f6;width:16px;height:16px;flex-shrink:0}</style>';
  include $template;
}

/* ======================== SHORTCODE ======================== */

add_action('init', function () { add_shortcode('ai_chat', 'ai_sr_chat_shortcode'); });
add_action('wp_enqueue_scripts', 'ai_sr_chat_assets');

function ai_sr_chat_assets() {
  global $post;
  if ($post && has_shortcode($post->post_content, 'ai_chat')) {
    wp_enqueue_style('ai-sr-chat', AI_SR_URL . 'public/assets/chat.css', [], AI_SR_VERSION);
    wp_enqueue_script('ai-sr-chat', AI_SR_URL . 'public/assets/chat.js', [], AI_SR_VERSION, true);
    wp_localize_script('ai-sr-chat', 'aiSrChat', [
      'restUrl' => get_rest_url(null, 'ai-router/v1/chat'),
      'nonce' => wp_create_nonce('wp_rest'),
      'siteName' => get_bloginfo('name'),
    ]);
  }
}

function ai_sr_chat_shortcode($atts = []) {
  $atts = shortcode_atts(['title' => 'AI Assistant', 'type' => 'customer'], $atts);
  $atts['type'] = in_array($atts['type'], ['customer', 'worker']) ? $atts['type'] : 'customer';
  ob_start();
  include AI_SR_PATH . 'public/views/chat-widget.php';
  return ob_get_clean();
}

/* ======================== CRON ======================== */

add_action('plugins_loaded', 'ai_sr_setup_cron');
function ai_sr_setup_cron() {
  if (!wp_next_scheduled('ai_router_daily_reset')) {
    wp_schedule_event(time(), 'daily', 'ai_router_daily_reset');
  }
}

add_action('ai_router_daily_reset', 'ai_sr_daily_reset');
function ai_sr_daily_reset() {
  update_option('ai_router_exhausted_models', []);
  update_option('ai_router_current', ['key' => 0, 'model' => 0]);
  $stats = get_option('ai_router_stats', []);
  $stats['today_responses'] = 0;
  $stats['last_reset'] = time();
  update_option('ai_router_stats', $stats);
  ai_sr_log('Daily reset', 'All exhausted models cleared');
}

add_action('plugins_loaded', 'ai_sr_setup_skill_cron');
function ai_sr_setup_skill_cron() {
  if (!wp_next_scheduled('ai_router_skill_consolidate')) {
    wp_schedule_event(time() + 3600, 'daily', 'ai_router_skill_consolidate');
  }
}

add_action('ai_router_skill_consolidate', 'ai_sr_consolidate_skills');

/* ======================== SETTINGS LINK ======================== */

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ai_sr_settings_link');
function ai_sr_settings_link($links) {
  $url = admin_url('admin.php?page=ai-smart-router');
  $links[] = '<a href="' . $url . '">Settings</a>';
  return $links;
}