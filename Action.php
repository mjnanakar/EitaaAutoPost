<?php
namespace TypechoPlugin\EitaaAutoPost;

use Typecho\Widget;
use Typecho\Widget\Helper\Layout;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * Action مربوط به MiniApp ایتا
 *
 * URL: /eitaa/miniapp
 */
class Action extends \Typecho_Widget implements \Typecho_Widget_Interface_Do
{
    /**
     * متد اصلی عملیات
     */
    public function action()
    {
        $this->response->setContentType('application/json; charset=utf-8');

        try {
            $request  = $this->request;
            $options  = Options::alloc();
            $config   = $options->plugin('EitaaAutoPost');

            $botToken = isset($config->eitaaBotToken) ? trim($config->eitaaBotToken) : '';

            if (empty($botToken)) {
                $this->response->throwJson([
                    'ok'    => false,
                    'error' => 'eitaa_bot_token_not_configured',
                ]);
                return;
            }

            // initData می‌تواند از POST یا GET بیاید
            $initData = $request->get('initData', '');
            if (empty($initData) && isset($_POST['initData'])) {
                $initData = (string) $_POST['initData'];
            }

            if (empty($initData)) {
                $this->response->throwJson([
                    'ok'    => false,
                    'error' => 'initData_required',
                ]);
                return;
            }

            // اعتبارسنجی initData
            $validated = Plugin::verifyInitData($initData, $botToken, 600);

            if ($validated === false) {
                $this->response->throwJson([
                    'ok'    => false,
                    'error' => 'invalid_init_data',
                ]);
                return;
            }

            // استخراج user (JSON) و start_param
            $userData   = null;
            $startParam = isset($validated['start_param']) ? $validated['start_param'] : null;

            if (isset($validated['user'])) {
                $decoded = json_decode($validated['user'], true);
                if (is_array($decoded)) {
                    $userData = $decoded;
                }
            }

            $this->response->throwJson([
                'ok'          => true,
                'user'        => $userData,
                'start_param' => $startParam,
                'auth_date'   => isset($validated['auth_date']) ? (int) $validated['auth_date'] : null,
            ]);
        } catch (\Exception $e) {
            $this->response->throwJson([
                'ok'    => false,
                'error' => 'internal_error',
                'msg'   => $e->getMessage(),
            ]);
        }
    }
}