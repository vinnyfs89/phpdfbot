<?php

namespace App\Services;

use App\Commands\NewOpportunityCommand;
use App\Models\Opportunity;

use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\BotsManager;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\Objects\CallbackQuery;
use Telegram\Bot\Objects\Document;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\PhotoSize;
use Telegram\Bot\Objects\Update;

use \Exception;

/**
 * Class CommandsHandler
 */
class CommandsHandler
{

    /** @var BotsManager */
    private $botsManager;

    /** @var string */
    private $token;

    /** @var string */
    private $botName;

    /** @var Api */
    private $telegram;

    /** @var Update */
    private $update;

    /**
     * CommandsHandler constructor.
     * @param BotsManager $botsManager
     * @param string $botName
     * @param string $token
     * @param Update $update
     */
    public function __construct(BotsManager $botsManager, string $botName, string $token, Update $update)
    {
        $this->botsManager = $botsManager;
        $this->token = $token;
        $this->botName = $botName;
        $this->update = $update;
        $this->telegram = $this->botsManager->bot($botName);
        $this->processUpdate($update);
    }

    /**
     * @param BotsManager $botsManager
     * @param string $botName
     * @param string $token
     * @param Update $update
     * @return CommandsHandler
     */
    public static function make(BotsManager $botsManager, string $botName, string $token, Update $update)
    {
        return new static($botsManager, $botName, $token, $update);
    }

    /**
     * Process the update coming from bot interface
     *
     * @param Update $update
     * @return mixed
     */
    private function processUpdate(Update $update)
    {
        try {
            /** @var Message $message */
            $message = $update->getMessage();
            /** @var CallbackQuery $callbackQuery */
            $callbackQuery = $update->get('callback_query');

            if (strpos($message->text, '/') === 0) {
                $command = explode(' ', $message->text);
                $this->processCommand($command[0]);
            }
            if (filled($callbackQuery)) {
                $this->processCallbackQuery($callbackQuery);
            } elseif (filled($message)) {
                $this->processMessage($message);
            }
        } catch (Exception $exception) {
            $this->error($exception);
        }

        return null;
    }

    /**
     * Process the Callback query coming from bot interface
     *
     * @param CallbackQuery $callbackQuery
     * @throws TelegramSDKException
     */
    private function processCallbackQuery(CallbackQuery $callbackQuery): void
    {
        $data = $callbackQuery->get('data');
        $data = explode(' ', $data);
        switch ($data[0]) {
            case Opportunity::CALLBACK_APPROVE:
                $opportunity = Opportunity::find($data[1]);
                $opportunity->status = Opportunity::STATUS_ACTIVE;
                $opportunity->save();
                Artisan::call(
                    'bot:populate:channel',
                    [
                        'process' => 'send',
                        'opportunity' => $opportunity->id
                    ]
                );
                break;
            case Opportunity::CALLBACK_REMOVE:
                Opportunity::find($data[1])->delete();
                break;
            default:
                Log::info('SWITCH_DEFAULT', $data);
                $this->processCommand($data[0]);
                break;
        }
        Log::info('DELETE_MESSAGE_ID', [$callbackQuery->message->messageId]);
        $this->telegram->deleteMessage([
            'chat_id' => env('TELEGRAM_GROUP_ADM'),
            'message_id' => $callbackQuery->message->messageId
        ]);
    }

    /**
     * Process the messages coming from bot interface
     *
     * @param Message $message
     * @throws Exception
     */
    private function processMessage(Message $message): void
    {
        /** @var Message $reply */
        $reply = $message->getReplyToMessage();
        /** @var PhotoSize $photos */
        /** @var PhotoSize $photo */
        $photos = $message->photo;
        /** @var Document $document */
        $document = $message->document;
        /** @var string $caption */
        $caption = $message->caption;

        if (filled($reply) && $reply->from->isBot && $reply->text === NewOpportunityCommand::TEXT) {
            $opportunity = new Opportunity();
            if (blank($message->text) && blank($caption)) {
                throw new Exception('Envie um texto para a vaga, ou o nome da vaga na legenda da imagem/documento.');
            }
            $text = $message->text ?? $caption;
            $title = str_replace("\n", ' ', $text);
            $opportunity->title = substr($title, 0, 50);
            $opportunity->description = $text;
            $opportunity->status = Opportunity::STATUS_INACTIVE;
            $opportunity->files = collect();
            $opportunity->telegram_user_id = $message->from->id;

            if (filled($photos)) {
                foreach ($photos as $photo) {
                    $opportunity->addFile($photo);
                }
            }
            if (filled($document)) {
                $opportunity->addFile($document->first());
            }

            $opportunity->save();

            $this->sendOpportunityToApproval($opportunity, $message);
        }
        $newMembers = $message->newChatMembers;
        Log::info('NEW_MEMBER', [$newMembers]);

        // TODO: Think more about this
        /*if (filled($newMembers)) {
            foreach ($newMembers as $newMember) {
                $name = $newMember->getFirstName();
                return $this->telegram->getCommandBus()->execute('start', $this->update, $name);
            }
        }*/
    }

    /**
     * Send opportunity to approval
     *
     * @param Opportunity $opportunity
     * @param Message $message
     */
    private function sendOpportunityToApproval(Opportunity $opportunity, Message $message): void
    {
        Artisan::call(
            'bot:populate:channel',
            [
                'process' => 'approval',
                'opportunity' => $opportunity->id,
                'message' => $message->messageId,
                'chat' => $message->chat->id,
            ]
        );
    }

    /**
     * Process the command
     *
     * @param string $command
     */
    private function processCommand(string $command): void
    {
        $command = str_replace('/', '', $command);

        $commands = $this->telegram->getCommands();

        if (array_key_exists($command, $commands)) {
            Telegram::processCommand($this->update);
        }
    }

    private function error(Exception $exception): void
    {
        Log::error('ERROR:', [$exception]);
        $this->telegram->sendMessage([
            'parse_mode' => 'Markdown',
            'text' => $exception->getMessage(),
            'chat_id' => $this->update->getChat()->id,
            'reply_to_message_id' => $this->update->getMessage()->messageId,
        ]);

//        $this->telegram->replyWithMessage([
//            'parse_mode' => 'Markdown',
//            'text' => $exception->getMessage()
//        ]);
    }
}
