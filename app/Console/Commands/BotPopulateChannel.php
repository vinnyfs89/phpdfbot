<?php

namespace App\Console\Commands;

use App\Helpers\Helper;
use App\Models\Notification;
use App\Models\Opportunity;

use Dacastro4\LaravelGmail\Facade\LaravelGmail;
use Dacastro4\LaravelGmail\Services\Message\Attachment;
use Dacastro4\LaravelGmail\Services\Message\Mail;

use DateTime;
use DateTimeZone;
use Exception;
use Goutte\Client;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

use JD\Cloudder\CloudinaryWrapper;
use JD\Cloudder\Facades\Cloudder;

use Symfony\Component\DomCrawler\Crawler;

use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Keyboard\Keyboard;

/**
 * Class BotPopulateChannel
 */
class BotPopulateChannel extends AbstractCommand
{
    /**
     * Gmail Labels
     */
    protected const LABEL_ENVIADO_PRO_BOT = 'Label_5517839157714334708';
    protected const LABEL_STILL_UNREAD = 'Label_7';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bot:populate:channel {process} {opportunity?} {message?} {chat?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command to populate the channel with new content';

    /**
     * The name of bot of this command
     *
     * @var string
     */
    protected $botName = 'phpdfbot';

    /** @var string */
    protected $channel;

    /** @var string */
    protected $appUrl;

    /** @var string */
    protected $group;

    /** @var string */
    protected $adm;

    /**
     * The emails must to contain at least one of this words
     *
     * @var array
     */
    protected $mustIncludeWords = [
        'desenvolvedor', 'desenvolvimento', 'programador', 'developer', 'analista', 'php', 'arquiteto', 'suporte',
        'devops', 'dev-ops', 'teste', '"banco de dados"', '"segurança da informação"', 'design', 'front-end',
        'frontend', 'back-end', 'backend', 'scrum', 'tecnologia', '"gerente de projetos"', '"analista de dados"',
        '"administrador de dados"', 'infra', 'software', 'oportunidade', 'hardware', 'java', 'javascript', 'python',
        'informática', 'designer', 'react', 'vue', 'wordpress', 'sistemas', 'full-stack', '"full stack"', 'fullstack',
        'computação', '"gerente de negócios"', 'tecnologias', 'iot', '"machine learning"', '"big data"',
        '"gerenciamento de projetos"', '"gerenciamento de negócios"',
    ];

    /**
     * Estados
     * @var array
     */
    protected $estadosBrasileiros = [
        'AC' => 'Acre',
        'AL' => 'Alagoas',
        'AP' => 'Amapá',
        'AM' => 'Amazonas',
        'BA' => 'Bahia',
        'CE' => 'Ceará',
        'DF' => '"Distrito Federal"',
        'ES' => '"Espírito Santo"',
        'GO' => 'Goiás',
        'MA' => 'Maranhão',
        'MT' => '"Mato Grosso"',
        'MS' => '"Mato Grosso do Sul"',
        'MG' => '"Minas Gerais"',
        'PA' => 'Pará',
        'PB' => 'Paraíba',
        'PR' => 'Paraná',
        'PE' => 'Pernambuco',
        'PI' => 'Piauí',
        'RJ' => '"Rio de Janeiro"',
        'RN' => '"Rio Grande do Norte"',
        'RS' => '"Rio Grande do Sul"',
        'RO' => 'Rondônia',
        'RR' => 'Roraima',
        'SC' => '"Santa Catarina"',
        'SP' => '"São Paulo"',
        'SE' => 'Sergipe',
        'TO' => 'Tocantins',
        // cidades
        'BSB' => 'Brasília',
        'BH' => '"Belo Horizonte"',
    ];

    /**
     * Tags
     * @var array
     */
    protected $commonTags = [
        'remote', 'remoto', 'júnior', 'junior', 'pleno', 'senior', 'sênior', 'pj', 'clt', 'laravel', 'symfony',
        'e-commerce', 'ecommerce', 'mysql', 'js', 'graphql', 'ui/ux', 'css', 'html', 'photoshop', '"design thinking"',
        'node', 'docker', 'kubernets', 'angular', 'react', 'android', 'ios', '"teste unitário"', 'swift',
        '"objective-c"', 'linux', 'postgresql', 'dba', 'bootstrap', 'webpack', 'microservices', 'selenium', 'scrum',
        'redes', 'tomcat', 'hibernate', 'spring', 'git', 'oracle', 'ionic', 'ux', 'geoprocessamento', 'postgis',
        '"zend framework"', 'oraclesql', 'kotlin', 'devops', 'tdd', 'elixir', 'clojure', 'scala', '"start-up"',
        'startup', 'fintech', 'alocado', 'presencial', '"continuous integration"', '"continuous deployment"', 'ruby',
        'nativescript', 'sass',
    ];

    /**
     * Execute the console command.
     *
     * @throws TelegramSDKException
     */
    public function handle(): void
    {
        $this->channel = env('TELEGRAM_CHANNEL');
        $this->appUrl = env('APP_URL');
        $this->group = env('TELEGRAM_GROUP');
        $this->adm = env('TELEGRAM_GROUP_ADM');

        switch ($this->argument('process')) {
            case 'process':
                $this->processOpportunities();
                break;
            case 'notify':
                $this->notifyGroup();
                break;
            case 'send':
                $this->sendOpportunityToChannel($this->argument('opportunity'));
                break;
            case 'approval':
                $opportunityId = $this->argument('opportunity');
                $messageId = $this->argument('message');
                $chatId = $this->argument('chat');
                $this->sendOpportunityToApproval($opportunityId, $messageId, $chatId);
                break;
            default:
                // Do something
                break;
        }
    }

    /**
     * Retrieve the Opportunities objects and send them to approval
     *
     * @throws TelegramSDKException
     */
    protected function processOpportunities(): void
    {
        try {
            $opportunities = $this->createOpportunities();
            foreach ($opportunities as $opportunity) {
                $this->sendOpportunityToApproval($opportunity->id);
            }
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
            $this->log($exception, 'FALHA_AO_PROCESSAR_OPORTUNIDADES', __FUNCTION__);
        }
    }

    /**
     * Get messages from source and create objects from them
     *
     * @return Collection
     * @throws TelegramSDKException
     */
    protected function createOpportunities(): Collection
    {
        $opportunitiesRaw = $this->getMessagesFromGMail();
        $opportunitiesRaw = array_merge(
            $opportunitiesRaw,
            $this->getMessagesFromGithub(),
            $this->getMessagesFromComoEQueTaLa(),
            $this->getMessagesFromQueroWorkar()
        );

        $opportunities = array_map(function ($rawOpportunity) {
            $opportunity = new Opportunity();
            if (array_key_exists(Opportunity::COMPANY, $rawOpportunity)) {
                $opportunity->company = $rawOpportunity[Opportunity::COMPANY];
            }
            if (array_key_exists(Opportunity::LOCATION, $rawOpportunity)) {
                $opportunity->location = $rawOpportunity[Opportunity::LOCATION];
            }
            if (array_key_exists(Opportunity::FILES, $rawOpportunity)) {
                $opportunity->files = collect($rawOpportunity[Opportunity::FILES]);
            }
            $description = $this->sanitizeBody($rawOpportunity[Opportunity::DESCRIPTION]);
            $description .= $this->getHashtagFilters($description, $rawOpportunity[Opportunity::TITLE]);
            $opportunity->title = $this->sanitizeSubject($rawOpportunity[Opportunity::TITLE]);
            $opportunity->description = $description;
            $opportunity->save();
            return $opportunity;
        }, $opportunitiesRaw);

        return collect($opportunities);
    }

    /**
     * Return the an array of messages, then remove messages from email
     *
     * @return array
     * @throws TelegramSDKException
     */
    protected function getMessagesFromGMail(): array
    {
        $opportunities = [];
        try {
            $messages = $this->fetchGMailMessages();
            /** @var Mail $message */
            foreach ($messages as $message) {
                $opportunities[] = [
                    'title' => $message->getSubject(),
                    'description' => $this->getMessageBody($message),
                    'files' => $this->getMailAttachments($message),
                ];
                $message->markAsRead();
                $message->addLabel(self::LABEL_ENVIADO_PRO_BOT);
                $message->removeLabel(self::LABEL_STILL_UNREAD);
                $message->sendToTrash();
            }
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
            $this->log($exception, 'FALHA_AO_PROCESSAR_GMAIL', __FUNCTION__);
        }
        return $opportunities;
    }

    /**
     * Get array of URL for attachments files
     *
     * @param Mail $message
     * @return array
     * @throws TelegramSDKException
     */
    protected function getMailAttachments(Mail $message): array
    {
        $files = [];
        try {
            if ($message->hasAttachments()) {
                $attachments = $message->getAttachments();
                /** @var Attachment $attachment */
                foreach ($attachments as $attachment) {
                    if (!($attachment->getSize() < 50000
                        && strpos($attachment->getMimeType(), 'image') !== false)
                    ) {
                        $extension = File::extension($attachment->getFileName());
                        $fileName = Helper::base64UrlEncode($attachment->getFileName()) . '.' . $extension;
                        $filePath = $attachment->saveAttachmentTo($message->getId() . '/', $fileName, 'uploads');
                        $filePath = Storage::disk('uploads')->path($filePath);
                        try {
                            list($width, $height) = getimagesize($filePath);
                            /** @var CloudinaryWrapper $cloudImage */
                            $cloudImage = Cloudder::upload($filePath, null);
                            $fileUrl = $cloudImage->secureShow(
                                $cloudImage->getPublicId(),
                                [
                                    'width' => $width,
                                    'height' => $height
                                ]
                            );
                            $files[] = $fileUrl;
                        } catch (Exception $exception) {
                            $this->error($exception->getMessage());
                            $this->log($exception, 'FALHA_AO_GETIMAGESIZE', $filePath);
                        }
                    }
                }
            }
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
            $this->log($exception, 'FALHA_AO_PROCESSAR_ATTACHMENTS', __FUNCTION__);
        }
        return $files;
    }

    /**
     * Walks the GMail looking for specifics opportunity messages
     *
     * @return array
     */
    protected function fetchGMailMessages(): array
    {
        $words = '{' . implode(' ', $this->mustIncludeWords) . '}';
        $groups = [
            'gebeoportunidades@googlegroups.com',
            'profissaofuturowindows@googlegroups.com',
            'nvagas@googlegroups.com',
            'leonardoti@googlegroups.com',
            'clubinfobsb@googlegroups.com',
            'vagas@noreply.github.com',
        ];
        $fromTo = [];
        foreach ($groups as $group) {
            $fromTo[] = 'list:' . $group;
            $fromTo[] = 'to:' . $group;
            $fromTo[] = 'bcc:' . $group;
        }

        $fromTo = '{' . implode(' ', $fromTo) . '}';

        $query = "$fromTo $words is:unread";
        /** @var \Google_Service_Gmail_Thread $threads */
        $threads = LaravelGmail::message()->service->users_messages->listUsersMessages('me', [
            'q' => $query,
            //'maxResults' => 5
        ]);

        $messages = [];
        $allMessages = $threads->getMessages();
        foreach ($allMessages as $message) {
            $messages[] = new Mail($message, true);
        }
        return $messages;
    }

    /**
     * Prepare and send the opportunity to the channel, then update the TelegramId in database
     *
     * @param int $opportunityId
     * @throws TelegramSDKException
     */
    protected function sendOpportunityToChannel(int $opportunityId): void
    {
        /** @var Opportunity $opportunity */
        $opportunity = Opportunity::find($opportunityId);

        $messageSentId = $this->sendOpportunity($opportunity, $this->channel);
        $messageSentId = reset($messageSentId);
        if ($messageSentId) {
            $opportunity->telegram_id = $messageSentId;
            $opportunity->status = Opportunity::STATUS_ACTIVE;
            $opportunity->save();

            $this->notifyUser($opportunity);
        }
    }

    /**
     * Notify the send user, that opportunity was published on channel
     *
     * @param Opportunity $opportunity
     * @throws TelegramSDKException
     */
    protected function notifyUser(Opportunity $opportunity): void
    {
        if ($opportunity->telegram_user_id) {
            try {
                $link = "https://t.me/VagasBrasil_TI/{$opportunity->telegram_id}";
                $this->telegram->sendMessage([
                    'chat_id' => $opportunity->telegram_user_id,
                    'parse_mode' => 'Markdown',
                    'text' => "Sua vaga '[$opportunity->title]($link)' foi publicada no canal @VagasBrasil\\_TI.",
                ]);
            } catch (Exception $exception) {
                $link = "https://t.me/VagasBrasil_TI/{$opportunity->telegram_id}";
                $this->telegram->sendMessage([
                    'chat_id' => $opportunity->telegram_user_id,
                    'text' => "Sua vaga '$link' foi publicada no canal @VagasBrasil_TI.",
                ]);
            }
        }
    }

    /**
     * @param Opportunity $opportunity
     * @param int $chatId
     * @param array $options
     * @return array
     * @throws TelegramSDKException
     */
    protected function sendOpportunity(Opportunity $opportunity, $chatId, array $options = []): array
    {
        $messageTexts = $this->formatTextOpportunity($opportunity);
        $messageSentIds = [];
        $lastSentID = null;
        $messageSent = null;
        foreach ($messageTexts as $messageText) {
            $sendMsg = array_merge([
                'chat_id' => $chatId,
                'parse_mode' => 'Markdown',
                'text' => $messageText,
            ], $options);

            if ($lastSentID) {
                $sendMsg['reply_to_message_id'] = $lastSentID;
            }

            try {
                $messageSent = $this->telegram->sendMessage($sendMsg);
                $messageSentIds[] = $messageSent->messageId;
            } catch (Exception $exception) {
                if ($exception->getCode() === 400) {
                    try {
                        $sendMsg['text'] = $this->removeMarkdown($messageText);
                        unset($sendMsg['Markdown']);
                        $messageSent = $this->telegram->sendMessage($sendMsg);
                        $messageSentIds[] = $messageSent->messageId;
                    } catch (Exception $exception2) {
                        $this->log($exception, 'FALHA_AO_ENVIAR_TEXTPLAIN' . $chatId, [$sendMsg]);
                    }
                }
                $this->log($exception, 'FALHA_AO_ENVIAR_MARKDOWN' . $chatId, [$sendMsg]);
            }

            if ($messageSent) {
                $lastSentID = $messageSent->messageId;
            }
        }
        return $messageSentIds;
    }

    /**
     * Remove the Telegram Markdown from messages
     *
     * @param string $message
     * @return string
     */
    protected function removeMarkdown(string $message): string
    {
        $message = str_replace(['*', '_', '`'], '', $message);
        return trim($message, '[]');
    }

    /**
     * Remove BBCode from strings
     *
     * @param string $message
     * @return string
     */
    protected function removeBBCode(string $message): string
    {
        $message = preg_replace('#[\(\[\{][^\]]+[\)\]\}]#', '', $message);
        return trim($message);
    }

    /**
     * Remove the Brackets from strings
     *
     * @param string $message
     * @return string
     */
    protected function removeBrackets(string $message): string
    {
        $message = trim($message, '[]{}()');
        $message = preg_replace('#[\(\[\{\)\]\}]#', '--', $message);
        $message = preg_replace('#(-){2,}#', ' - ', $message);
        $message = preg_replace('#( ){2,}#', ' ', $message);
        return trim($message);
    }

    /**
     * Escapes the Markdown to avoid bad request in Telegram
     *
     * @param string $message
     * @return string
     */
    protected function escapeMarkdown(string $message): string
    {
        $message = str_replace(['*', '_', '`', '[', ']'], ["\\*", "\\_", "\\`", "\\[", '\\]'], $message);
        return trim($message);
    }

    /**
     * Replace the Markdown to avoid bad request in Telegram
     *
     * @param string $message
     * @return string
     */
    protected function replaceMarkdown(string $message): string
    {
        $message = str_replace(['*', '_', '`', '[', ']'], ' ', $message);
        $message = preg_replace('#( ){2,}#', ' ', $message);
        return trim($message);
    }

    /**
     * Sanitizes the subject and remove annoying content
     *
     * @param string $message
     * @return string
     */
    protected function sanitizeSubject(string $message): string
    {
        $message = preg_replace('/^(RE|FW|FWD|ENC|VAGA|Oportunidade)S?:?/im', '', $message, -1);
        $message = preg_replace('/(\d{0,999} (view|application)s?)/', '', $message);
        $message = str_replace(['[ClubInfoBSB]', '[leonardoti]', '[NVagas]', '[ProfissãoFuturo]'], '', $message);
        $message = str_replace("\n", '', $message);
        return trim($message);
    }

    /**
     * Sanitizes the message, removing annoying and unnecessary content
     *
     * @param string $message
     * @return string
     */
    protected function sanitizeBody(string $message): string
    {
        if ($message) {
            $delimiters = [
                'As informações contidas neste',
                'You are receiving this because you are subscribed to this thread',
                'Você recebeu esta mensagem porque está inscrito para o Google',
                'Você recebeu essa mensagem porque',
                'Você está recebendo esta mensagem porque',
                'Esta mensagem pode conter informa',
                'Você recebeu esta mensagem porque',
                'Antes de imprimir',
                'This message contains',
                'NVagas Conectando',
                'Atenciosamente',
                'Att.',
                'Att,',
                'AVISO DE CONFIDENCIALIDADE',
                // Remove
                'Receba vagas no whatsapp',
                '-- Linkedin: www.linkedin.com/company/clube-de-vagas/',
                'www.linkedin.com/company/clube-de-vagas/',
                'linkedin.com/company/clube-de-vagas/',
                'Cordialmente',
                'Tiago Romualdo Souza',
                '--'
            ];

            $messageArray = explode($delimiters[0], str_replace($delimiters, $delimiters[0], $message));

            $message = $messageArray[0];

            $message = $this->removeTagsAttributes($message);
            $message = $this->removeEmptyTagsRecursive($message);
            $message = $this->closeOpenTags($message);

            $message = $this->removeMarkdown($message);

            $message = str_ireplace(['<3'], '❤️', $message);
            $message = str_ireplace(['<strong>', '<b>', '</b>', '</strong>'], '*', $message);
            $message = str_ireplace(['<i>', '</i>', '<em>', '</em>'], '_', $message);
            $message = str_ireplace([
                '<h1>', '</h1>', '<h2>', '</h2>', '<h3>', '</h3>', '<h4>', '</h4>', '<h5>', '</h5>', '<h6>', '</h6>'
            ], '`', $message);
            $message = str_replace(['<ul>', '<ol>', '</ul>', '</ol>'], '', $message);
            $message = str_replace('<li>', '•', $message);
            $message = preg_replace('/<br(\s+)?\/?>/i', "\n", $message);
            $message = preg_replace('/<p[^>]*?>/', "\n", $message);
            $message = str_replace(["</p>", '</li>'], "\n", $message);
            $message = strip_tags($message);

            $message = str_replace(['**', '__', '``'], '', $message);
            $message = str_replace(['* *', '_ _', '` `', '*  *', '_  _', '`  `'], '', $message);
            $message = preg_replace("/([\r\n])+/m", "\n", $message);
            $message = preg_replace("/\n{2,}/m", "\n", $message);
            $message = preg_replace("/\s{2,}/m", ' ', $message);
            $message = trim($message, " \t\n\r\0\x0B--");

            $message = preg_replace("/cid:image(.+)/m", '', $message);

            $message = str_replace('GrupoClubedeVagas', 'phpdfvagas', $message);
            $message = preg_replace('/(.+)(chat\.whatsapp\.com\/)(.+)/m', 'http://bit.ly/phpdf-official', $message);

        }
        return trim($message);
    }

    /**
     * Remove attributes from HTML tags
     *
     * @param string $message
     * @return string
     */
    protected function removeTagsAttributes(string $message): string
    {
        return preg_replace("/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i", '<$1$2>', $message);
    }

    /**
     * Closes the HTML open tags
     *
     * @param string $message
     * @return string
     */
    protected function closeOpenTags(string $message): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($message, 'HTML-ENTITIES', 'UTF-8'));
        $mock = new \DOMDocument;
        $body = $dom->getElementsByTagName('body')->item(0);
        if (is_object($body)) {
            foreach ($body->childNodes as $child) {
                $mock->appendChild($mock->importNode($child, true));
            }
        }
        return trim(html_entity_decode($mock->saveHTML()));
    }

    /**
     * Removes HTML tags without any content
     *
     * @param string $str
     * @param string $repto
     * @return string
     */
    protected function removeEmptyTagsRecursive(string $str, string $repto = ''): string
    {
        return trim($str) === '' ? $str : preg_replace('/<([^<\/>]*)>([\s]*?|(?R))<\/\1>/imsU', $repto, $str);
    }

    /**
     * Prepare the opportunity text to send to channel
     *
     * @param Opportunity $opportunity
     * @return array
     */
    protected function formatTextOpportunity(Opportunity $opportunity): array
    {
        $description = $opportunity->description;
        if (strlen($description) < 200) {
            return [];
        }

        $template = sprintf(
            "*%s*",
            $opportunity->title
        );

        if ($opportunity->files && $opportunity->files->isNotEmpty()) {
            foreach ($opportunity->files as $file) {
                $template .= "\n\n" .
                    sprintf(
                        "[Image](%s)",
                        $file
                    );
            }
            // $this->escapeMarkdown($file)
        }

        $template .= sprintf(
            "\n\n*Descrição*\n%s",
            $description
        );

        if (filled($opportunity->location)) {
            $template .= sprintf(
                "\n\n*Localização*\n%s",
                $opportunity->location
            );
        }

        if (filled($opportunity->company)) {
            $template .= sprintf(
                "\n\n*Empresa*\n%s",
                $opportunity->company
            );
        }

        if (filled($opportunity->salary)) {
            $template .= sprintf(
                "\n\n*Salario*\n%s",
                $opportunity->salary
            );
        }

        $template .= $this->getGroupSign();
        return str_split(
            $template,
            4096
        );
    }

    /**
     * Get message body from GMail content
     *
     * @param Mail $message
     * @return bool|string
     */
    protected function getMessageBody(Mail $message): string
    {
        $htmlBody = $message->getHtmlBody();
        if (empty($htmlBody)) {
            $parts = $message->payload->getParts()[0]->getParts();
            if (count($parts) > 0) {
                $body = $parts[1]->getBody()->getData();
                $htmlBody = $message->getDecodedBody($body);
            }
        }
        return $htmlBody;
    }

    /**
     * Notifies the group with the latest opportunities in channel
     * Get all the unnotified opportunities, build a keyboard with the links, sends to the group, update the opportunity
     * and remove the previous notifications from group
     *
     * @return bool
     * @throws TelegramSDKException
     */
    protected function notifyGroup(): bool
    {
        try {
            $opportunities = Opportunity::whereNotNull('telegram_id');
            $opportunitiesArr = $opportunities->get();
            if ($opportunitiesArr->isNotEmpty()) {
                $lastNotifications = Notification::all();

                $firstOpportunityId = null;

                $listOpportunities = $opportunitiesArr->map(function ($opportunity) use (&$firstOpportunityId) {
                    $firstOpportunityId = null === $firstOpportunityId
                        ? $opportunity->telegram_id : $firstOpportunityId;
                    return sprintf(
                        "➩ [%s](%s)",
                        $this->replaceMarkdown($this->removeBrackets($opportunity->title)),
                        'https://t.me/VagasBrasil_TI/' . $opportunity->telegram_id
                    );
                })->implode("\n");

                $keyboard = Keyboard::make()->inline();
                $keyboard->row(Keyboard::inlineButton([
                    'text' => 'Ver vagas',
                    'url' => 'https://t.me/VagasBrasil_TI/' . $firstOpportunityId
                ]));

                $notificationMessage = [
                    'chat_id' => $this->group,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => $keyboard,
                    'text' => sprintf(
                        "%s\n\n[%s](%s)\n\n%s",
                        "Há novas vagas no canal!\nConfira: {$this->escapeMarkdown($this->channel)} $this->group 😉",
                        "🄿🄷🄿🄳🄵",
                        str_replace('/index.php', '', $this->appUrl) . '/img/phpdf.webp',
                        $listOpportunities
                    )
                ];

                $message = $this->telegram->sendMessage($notificationMessage);

                $notification = new Notification();
                $notification->telegram_id = $message->messageId;
                $notification->body = json_encode($notificationMessage);
                $notification->save();

                foreach ($lastNotifications as $lastNotification) {
                    try {
                        $this->telegram->deleteMessage([
                            'chat_id' => $this->group,
                            'message_id' => $lastNotification->telegram_id
                        ]);
                    } catch (Exception $exception) {
                        $this->log($exception, 'ERRO_AO_DELETAR_NOTIFICACAO');
                        $this->info($exception->getMessage());
                    }
                    $lastNotification->delete();
                }
                $opportunities->delete();
            }
        } catch (Exception $exception) {
            $this->log($exception, 'ERRO_AO_NOTIFICAR_GRUPO');
            $this->error($exception->getMessage());
            return false;
        }
        $this->info('The group was notified!');
        return true;
    }

    /**
     * Build the footer sign to the messages
     *
     * @return string
     */
    protected function getGroupSign(): string
    {
        return "\n\n*PHPDF*\n✅ *Canal:* @VagasBrasil\\_TI\n✅ *Grupo:* @phpdf";
    }

    /**
     * Get the results from crawler process, merge they and send to the channel
     *
     * @return array
     */
    protected function getMessagesFromGithub(): array
    {
        $githubSources = [
            'https://github.com/frontendbr/vagas/issues',
            'https://github.com/androiddevbr/vagas/issues',
            'https://github.com/CangaceirosDevels/vagas_de_emprego/issues',
            'https://github.com/CocoaHeadsBrasil/vagas/issues',
            'https://github.com/phpdevbr/vagas/issues',
            'https://github.com/vuejs-br/vagas/issues',
            'https://github.com/backend-br/vagas/issues',
        ];

        $opportunities = [];
        foreach ($githubSources as $githubSource) {
            $opportunities[] = $this->fetchMessagesFromGithub($githubSource);
        }
        return array_merge(
            ...$opportunities
        );
    }

    /**
     * Generate a log on server, and send a notification to admin
     *
     * @param Exception $exception
     * @param string $message
     * @param null $context
     * @throws TelegramSDKException
     */
    protected function log(Exception $exception, $message = '', $context = null): void
    {
        $referenceLog = $message . time() . '.log';
        Log::error($message, [$exception->getLine(), $exception, $context]);
        Storage::disk('logs')->put($referenceLog, json_encode([$context, $exception->getTrace()]));
        $referenceLog = Storage::disk('logs')->url($referenceLog);
        try {
            $this->telegram->sendDocument([
                'chat_id' => $this->adm,
                'document' => InputFile::create($referenceLog),
                'parse_mode' => 'HTML',
                'caption' => sprintf("<pre>\n%s\n</pre>", json_encode([
                    'message' => $message,
                    'exceptionMessage' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'referenceLog' => $referenceLog,
                ]))
            ]);
        } catch (Exception $exception2) {
            $this->telegram->sendDocument([
                'chat_id' => $this->adm,
                'document' => InputFile::create($referenceLog),
                'caption' => json_encode([
                    'message' => $message,
                    'exceptionMessage' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'referenceLog' => $referenceLog,
                ])
            ]);
        }
    }

    /**
     * Make a crawler in "comoequetala.com.br" website
     *
     * @return array
     */
    protected function getMessagesFromComoEQueTaLa(): array
    {
        $opportunities = [];
        $client = new Client();
        $crawler = $client->request('GET', 'https://comoequetala.com.br/vagas-e-jobs');
        $crawler->filter('.uk-list.uk-list-space > li')->each(function ($node) use (&$opportunities) {
            $skipDataCheck = env('CRAWLER_SKIP_DATA_CHECK');
            $client = new Client();
            $pattern = '#(' . implode('|', $this->mustIncludeWords) . ')#i';
            $pattern = str_replace('"', '', $pattern);
            if (preg_match_all($pattern, $node->text())) {
                $data = $node->filter('[itemprop="datePosted"]')->attr('content');
                $data = new DateTime($data);
                $today = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
                if ($skipDataCheck || $data->format('Ymd') === $today->format('Ymd')) {
                    $link = $node->filter('[itemprop="url"]')->attr('content');
                    $crawler2 = $client->request('GET', $link);
                    $title = $crawler2->filter('[itemprop="title"],h3')->text();
                    $description = [
                        $crawler2->filter('[itemprop="description"]')->count() ?
                            $crawler2->filter('[itemprop="description"]')->html() : '',
                        $crawler2->filter('.uk-container > .uk-grid-divider > .uk-width-1-1:last-child')->count()
                            ? $crawler2->filter('.uk-container > .uk-grid-divider > .uk-width-1-1:last-child')->html()
                            : '',
                        '*Como se candidatar:* ' . $link
                    ];
                    //$link = $node->filter('.uk-link')->text();
                    $company = $node->filter('.vaga_empresa')->count() ? $node->filter('.vaga_empresa')->text() : '';
                    $location = trim($node->filter('[itemprop="addressLocality"]')->text()) . '/'
                        . trim($node->filter('[itemprop="addressRegion"]')->text());

                    $opportunities[] = [
                        Opportunity::TITLE => $title,
                        Opportunity::DESCRIPTION => implode("\n\n", $description),
                        Opportunity::COMPANY => trim($company),
                        Opportunity::LOCATION => trim($location),
                    ];
                }
            }
        });
        return $opportunities;
    }

    /**
     * Make a crawler in "queroworkar.com.br" website
     *
     * @return array
     */
    protected function getMessagesFromQueroWorkar(): array
    {
        $opportunities = [];
        $client = new Client();
        $crawler = $client->request('GET', 'http://queroworkar.com.br/blog/jobs/');
        $crawler->filter('.loadmore-item')->each(function ($node) use (&$opportunities) {
            $skipDataCheck = env('CRAWLER_SKIP_DATA_CHECK');
            /** @var Crawler $node */
            $client = new Client();
            $jobsPlace = $node->filter('.job-location');
            if ($jobsPlace->count()) {
                $jobsPlace = $jobsPlace->text();
                if (preg_match_all('#(Em qualquer lugar|Brasil)#i', $jobsPlace)) {
                    $data = $node->filter('.job-date .entry-date')->attr('datetime');
                    $data = explode('T', $data);
                    $data = trim($data[0]);
                    $data = new DateTime($data);
                    $today = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
                    if ($skipDataCheck || $data->format('Ymd') === $today->format('Ymd')) {
                        $link = $node->filter('a')->first()->attr('href');
                        $crawler2 = $client->request('GET', $link);
                        $title = $crawler2->filter('.page-title')->text();
                        $description = $crawler2->filter('.job-desc')->html();
                        $description = str_ireplace(
                            '(adsbygoogle = window.adsbygoogle || []).push({});',
                            '',
                            $description
                        );
                        $description .= "\n\n*Como se candidatar:* " . $link;

                        $opportunities[] = [
                            Opportunity::TITLE => $title,
                            Opportunity::DESCRIPTION => $description,
                            Opportunity::COMPANY => trim($crawler2->filter('.company-title')->text()),
                            Opportunity::LOCATION => trim($crawler2->filter('.job-location')->text()),
                        ];
                    }
                }
            }
        });
        return $opportunities;
    }

    /**
     * Make a crawler in github opportunities channels
     *
     * @param string $url
     * @return array
     */
    protected function fetchMessagesFromGithub(string $url = ''): array
    {
        $opportunities = [];
        $client = new Client();
        $crawler = $client->request('GET', $url);
        $crawler->filter('[aria-label="Issues"] .Box-row')->each(function ($node) use (&$opportunities) {
            $skipDataCheck = env('CRAWLER_SKIP_DATA_CHECK');
            /** @var Crawler $node */
            $client = new Client();

            $data = $node->filter('relative-time')->attr('datetime');
            $data = new DateTime($data);
            $today = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));

            if ($skipDataCheck || $data->format('Ymd') === $today->format('Ymd')) {
                $link = $node->filter('a')->first()->attr('href');
                $link = 'https://github.com' . $link;
                $title = $node->filter('a')->first()->text();
                //d-block comment-body
                $crawler2 = $client->request('GET', $link);

                $opportunities[] = [
                    Opportunity::TITLE => trim($title),
                    Opportunity::DESCRIPTION => trim($crawler2->filter('.d-block.comment-body')->html()),
                ];
            }
        });
        return $opportunities;
    }

    /**
     * Append the hashtags relatives the to content
     *
     * @param string $message
     * @param string $title
     * @return string
     */
    protected function getHashtagFilters(string $message, string $title): string
    {
        $pattern = sprintf(
            '#(%s)#i',
            implode('|', array_merge($this->mustIncludeWords, $this->estadosBrasileiros, $this->commonTags))
        );

        $pattern = str_replace('"', '', $pattern);
        $allTags = '';
        if (preg_match_all($pattern, $title.$message, $matches)) {
            $tags = [];
            array_walk($matches[0], function ($item, $key) use (&$tags) {
                $tags[$key] = '#' . strtolower(str_replace([' ', '-'], '', $item));
            });
            $tags = array_unique($tags);
            $allTags = "\n\n" . implode(' ', $tags) . "\n\n";
        }
        return $allTags;
    }

    /**
     * Send opportunity to approval
     *
     * @param int $opportunityId
     * @param int $messageId
     * @param int $chatId
     * @throws TelegramSDKException
     */
    protected function sendOpportunityToApproval(int $opportunityId, int $messageId = null, int $chatId = null): void
    {
        $keyboard = Keyboard::make()
            ->inline()
            ->row(
                Keyboard::inlineButton([
                    'text' => 'Aprovar',
                    'callback_data' => implode(' ', [Opportunity::CALLBACK_APPROVE, $opportunityId])
                ]),
                Keyboard::inlineButton([
                    'text' => 'Remover',
                    'callback_data' => implode(' ', [Opportunity::CALLBACK_REMOVE, $opportunityId])
                ])
            );

        $messageToSend = [
            'reply_markup' => $keyboard,
        ];

        if ($messageId && $chatId) {
            $fwdMessage = $this->telegram->forwardMessage([
                'chat_id' => $this->adm,
                'from_chat_id' => $chatId,
                'message_id' => $messageId
            ]);
            $messageToSend['reply_to_message_id'] = $fwdMessage->messageId;
            $messageToSend['parse_mode'] = 'Markdown';
            $messageToSend['chat_id'] = $this->adm;
            $messageToSend['text'] = 'Aprovar?';

            $this->telegram->sendMessage($messageToSend);
        } else {
            /** @var Opportunity $opportunity */
            $opportunity = Opportunity::find($opportunityId);
            $this->sendOpportunity($opportunity, $this->adm, $messageToSend);
        }
    }
}
