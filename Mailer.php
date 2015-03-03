<?php
/**
 * Contains the Mailer class.
 * 
 * @link http://www.creationgears.com/
 * @copyright Copyright (c) 2014 Nicola Puddu
 * @license http://www.gnu.org/copyleft/gpl.html
 * @package nickcv/yii2-mandrill
 * @author Nicola Puddu <n.puddu@outlook.com>
 */

namespace nickcv\mandrill;

use yii\mail\BaseMailer;
use yii\base\InvalidConfigException;
use nickcv\mandrill\Message;
use Mandrill;
use Mandrill_Error;

/**
 * Mailer is the class that consuming the Message object sends emails thorugh
 * the Mandrill API.
 *
 * @author Nicola Puddu <n.puddu@outlook.com>
 * @version 1.0
 */
class Mailer extends BaseMailer
{

    const STATUS_SENT = 'sent';
    const STATUS_QUEUED = 'queued';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_REJECTED = 'rejected';
    const STATUS_INVALID = 'invalid';
    const LOG_CATEGORY = 'mandrill';

    /**
     * @var string Mandrill API key
     */
    private $_apikey;

    /**
     * @var string message default class name.
     */
    public $messageClass = 'nickcv\mandrill\Message';

    /**
     * @var Mandrill the Mandrill instance
     */
    private $_mandrill;

    /**
     * Checks that the API key has indeed been set.
     * 
     * @inheritdoc
     */
    public function init()
    {
        if (!$this->_apikey) {
            throw new InvalidConfigException('"' . get_class($this) . '::apikey" cannot be null.');
        }

        try {
            $this->_mandrill = new Mandrill($this->_apikey);
        } catch (\Exception $exc) {
            \Yii::error($exc->getMessage());
            throw new Exception('an error occurred with your mailer. Please check the application logs.', 500);
        }
    }

    /**
     * Sets the API key for Mandrill
     * 
     * @param string $apikey the Mandrill API key
     * @throws InvalidConfigException
     */
    public function setApikey($apikey)
    {
        if (!is_string($apikey)) {
            throw new InvalidConfigException('"' . get_class($this) . '::apikey" should be a string, "' . gettype($apikey) . '" given.');
        }

        $apikey = trim($apikey);
        if (!strlen($apikey) > 0) {
            throw new InvalidConfigException('"' . get_class($this) . '::apikey" length should be greater than 0.');
        }

        $this->_apikey = $apikey;
    }

    /**
     * Sends the specified message.
     * 
     * @param Message $message the message to be sent
     * @return boolean whether the message is sent successfully
     */
    protected function sendMessage($message)
    {
        $address = $address = implode(', ', $message->getTo());
        \Yii::info('Sending email "' . $message->getSubject() . '" to "' . $address . '"', self::LOG_CATEGORY);

        try {
            return $this->wasMessageSentSuccesfully($this->_mandrill->messages->send($message->getMandrillMessageArray()));
        } catch (Mandrill_Error $e) {
            \Yii::error('A mandrill error occurred: ' . get_class($e) . ' - ' . $e->getMessage(), self::LOG_CATEGORY);
            return false;
        }
    }
    
    private function getMergeParams($params) {
        $merge = [];
        foreach ($params as $key => $value) {
            $merge[] = ['name' => $key, 'content' => $value];
        }
        return $merge;
    }
    
    public function compose($templateName = null, array $params = []) {
        $message = parent::compose();
        
        try {
            $rendered = $this->_mandrill->templates->render($templateName, [], $this->getMergeParams($params));
            $message->setHtmlBody($rendered['html']);
            return $message;
        } catch (Mandrill_Error $e) {
            \Yii::error('A mandrill error occurred: ' . get_class($e) . ' - ' . $e->getMessage(), self::LOG_CATEGORY);
        }
        
        // fall back to rendering views
        return parent::compose($templateName, $params);
    }

    /**
     * parse the mandrill response and returns false if any message was either invalid or rejected
     * 
     * @param array $mandrillResponse
     * @return boolean
     */
    private function wasMessageSentSuccesfully($mandrillResponse)
    {
        $return = true;
        foreach ($mandrillResponse as $recipient) {
            switch ($recipient['status']) {
                case self::STATUS_INVALID:
                    $return = false;
                    \Yii::warning('the email for "' . $recipient['email'] . '" has not been sent: status "' . $recipient['status'] . '"', self::LOG_CATEGORY);
                    break;
                case self::STATUS_QUEUED:
                    \Yii::info('the email for "' . $recipient['email'] . '" is now in a queue waiting to be sent.', self::LOG_CATEGORY);
                    break;
                case self::STATUS_REJECTED:
                    $return = false;
                    \Yii::warning('the email for "' . $recipient['email'] . '" has been rejected: reason "' . $recipient['reject_reason'] . '"', self::LOG_CATEGORY);
                    break;
                case self::STATUS_SCHEDULED:
                    \Yii::info('the email submission for "' . $recipient['email'] . '" has been scheduled.', self::LOG_CATEGORY);
                    break;
                case self::STATUS_SENT:
                    \Yii::info('the email for "' . $recipient['email'] . '" has been sent.', self::LOG_CATEGORY);
                    break;
            }
        }

        return $return;
    }

}
