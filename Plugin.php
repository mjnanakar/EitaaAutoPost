<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * ارسال خودکار مطالب منتشرشده به کانال ایتا از طریق ایتایار
 *
 * @author   mjnanakar
 * @version 1.0.0
 */
class EitaaAutoPost_Plugin implements Typecho_Plugin_Interface
{
    /**
     * فعال‌سازی پلاگین
     */
    public static function activate()
    {
        // هوک بعد از انتشار مطلب (پست)
        Typecho_Plugin::factory('Widget_Contents_Post_Edit')->finishPublish = array('EitaaAutoPost_Plugin', 'send');

        // در صورت نیاز، برای برگه‌ها هم می‌توان فعال کرد:
        // Typecho_Plugin::factory('Widget_Contents_Page_Edit')->finishPublish = array('EitaaAutoPost_Plugin', 'send');

        return _t('پلاگین EitaaAutoPost با موفقیت فعال شد. لطفاً تنظیمات را کامل کنید.');
    }

    /**
     * غیرفعال‌سازی پلاگین
     */
    public static function deactivate()
    {
        return _t('پلاگین EitaaAutoPost غیرفعال شد.');
    }

    /**
     * تنظیمات عمومی پلاگین (در پنل مدیریت)
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // توکن ایتایار
        $token = new Typecho_Widget_Helper_Form_Element_Text(
            'token', null, '',
            _t('توکن ایتایار'),
            _t('توکن را دقیقاً همان‌طور که در ایتایار به شما داده شده وارد کنید. مثال: <code>bot209540:d53056d5-xxxx-xxxx-xxxx-xxxxxxxxxxxx</code>')
        );
        $form->addInput($token->addRule('required', _t('وارد کردن توکن اجباری است')));

        // شناسه کانال (chat_id)
        $chatId = new Typecho_Widget_Helper_Form_Element_Text(
            'chat_id', null, '',
            _t('شناسه کانال (chat_id)'),
            _t('برای کانال عمومی، همان آیدی بدون @ را وارد کنید. مثال: <code>mjnanacar</code>')
        );
        $form->addInput($chatId->addRule('required', _t('وارد کردن شناسه کانال اجباری است')));

        // قالب متن پیام
        $template = new Typecho_Widget_Helper_Form_Element_Textarea(
            'template', null,
"عنوان: {title}

خلاصه:
{excerpt}

لینک:
{link}",
            _t('قالب پیام ارسالی به ایتا'),
            _t('می‌توانید از متغیرهای {title} ،{excerpt} و {link} در متن استفاده کنید.')
        );
        $form->addInput($template);

        // طول خلاصه
        $excerptLength = new Typecho_Widget_Helper_Form_Element_Text(
            'excerptLength', null, '200',
            _t('حداکثر طول خلاصه مطلب'),
            _t('تعداد کاراکترهای خلاصه متن (پیش‌فرض: ۲۰۰).')
        );
        $form->addInput($excerptLength);

        // تأخیر در ارسال (ثانیه)
        $delay = new Typecho_Widget_Helper_Form_Element_Text(
            'delay', null, '0',
            _t('تأخیر در ارسال (ثانیه)'),
            _t('در صورت نیاز برای ارسال زمان‌بندی‌شده، چند ثانیه بعد از زمان فعلی تنظیم می‌شود. (۰ = بدون تأخیر)')
        );
        $form->addInput($delay);
    }

    /**
     * تنظیمات اختصاصی هر کاربر (در این پلاگین استفاده نمی‌شود)
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * تابعی که بعد از انتشار پست صدا زده می‌شود
     *
     * @param array  $contents  داده‌های پست
     * @param object $class     شیء Widget_Contents_Post_Edit
     */
    public static function send($contents, $class)
    {
        // اگر وضعیت پست "انتشار" نیست یا هنوز زمان انتشار نرسیده، کاری نکن
        if ('publish' != $contents['visibility'] || $contents['created'] > time()) {
            return;
        }

        // دریافت تنظیمات سایت و پلاگین
        $options = Helper::options();
        $pluginOptions = $options->plugin('EitaaAutoPost');

        $token   = trim($pluginOptions->token);
        $chatId  = trim($pluginOptions->chat_id);
        $tpl     = (string)$pluginOptions->template;
        $excerptLength = intval($pluginOptions->excerptLength);
        $delay   = intval($pluginOptions->delay);

        if (empty($token) || empty($chatId)) {
            // تنظیمات ناقص؛ ارسال انجام نمی‌شود
            return;
        }

        // تولید لینک دائمی مطلب
        $type = $contents['type']; // post, page, ...
        $routeExists = (null != Typecho_Router::get($type));  // :contentReference[oaicite:1]{index=1}
        $pathInfo = $routeExists ? Typecho_Router::url($type, $contents) : '#';
        $permalink = Typecho_Common::url($pathInfo, $options->index);

        // عنوان
        $title = isset($contents['title']) ? $contents['title'] : '';

        // متن کامل (raw) برای ساخت خلاصه
        $rawText = '';
        if (isset($contents['text'])) {
            $rawText = $contents['text'];
        } elseif (isset($contents['markdown'])) {
            $rawText = $contents['markdown'];
        }

        // حذف تگ‌های HTML و تبدیل به خلاصه
        $plainText = trim(strip_tags($rawText));
        if ($excerptLength <= 0) {
            $excerptLength = 200;
        }

        if (mb_strlen($plainText, 'UTF-8') > $excerptLength) {
            $excerpt = mb_substr($plainText, 0, $excerptLength, 'UTF-8') . '...';
        } else {
            $excerpt = $plainText;
        }

        // جایگذاری متغیرها در قالب
      $text = strtr($tpl, array(
    '{title}'   => $title,
    '{excerpt}' => $excerpt,
    '{content}' => $plainText,  // کل محتوای پست
    '{link}'    => $permalink,
));

        // محاسبه زمان ارسال (برای پارامتر date)
        $date = 0;
        if ($delay > 0) {
            $date = time() + $delay;
        }

        // فراخوانی API ایتایار – متد sendMessage
        self::callEitaApi($token, 'sendMessage', array(
            'chat_id' => $chatId,   // برای کانال عمومی: آیدی بدون @ :contentReference[oaicite:2]{index=2}
            'text'    => $text,
            // 'pin' => false, // در صورت نیاز می‌توان پارامترهای دیگر API را هم اضافه کرد
            // 'disable_notification' => false,
            // 'view_to_delete' => 0,
            // 'reply_to_message_id' => null,
            'date'    => $date > 0 ? $date : null,
        ));
    }

    /**
     * تابع کمکی برای فراخوانی متدهای API ایتایار
     *
     * @param string $token
     * @param string $method
     * @param array  $params
     */
    protected static function callEitaApi($token, $method, array $params = array())
    {
        $url = 'https://eitaayar.ir/api/' . $token . '/' . $method;

        // حذف پارامترهای null
        foreach ($params as $k => $v) {
            if ($v === null) {
                unset($params[$k]);
            }
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // در صورت نیاز به غیرفعال‌کردن SSL (در محیط تست؛ در تولید بهتر است فعال بماند)
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        // در صورت نیاز می‌توان لاگ خطا را در فایل لاگ نوشت
        // if ($error) {
        //     error_log('EitaaAutoPost error: ' . $error);
        // }
        // error_log('EitaaAutoPost response: ' . $response);
    }
}