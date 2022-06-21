<?php

namespace Appwrite\Event;

use InvalidArgumentException;
use Resque;
use Utopia\Database\Document;

class Event
{
    public const DATABASE_QUEUE_NAME = 'v1-database';
    public const DATABASE_CLASS_NAME = 'DatabaseV1';

    public const DELETE_QUEUE_NAME = 'v1-deletes';
    public const DELETE_CLASS_NAME = 'DeletesV1';

    public const AUDITS_QUEUE_NAME = 'v1-audits';
    public const AUDITS_CLASS_NAME = 'AuditsV1';

    public const MAILS_QUEUE_NAME = 'v1-mails';
    public const MAILS_CLASS_NAME = 'MailsV1';

    public const FUNCTIONS_QUEUE_NAME = 'v1-functions';
    public const FUNCTIONS_CLASS_NAME = 'FunctionsV1';

    public const WEBHOOK_QUEUE_NAME = 'v1-webhooks';
    public const WEBHOOK_CLASS_NAME = 'WebhooksV1';

    public const CERTIFICATES_QUEUE_NAME = 'v1-certificates';
    public const CERTIFICATES_CLASS_NAME = 'CertificatesV1';

    public const BUILDS_QUEUE_NAME = 'v1-builds';
    public const BUILDS_CLASS_NAME = 'BuildsV1';

    protected string $queue = '';
    protected string $class = '';
    protected string $event = '';
    protected array $params = [];
    protected array $payload = [];
    protected ?Document $project = null;
    protected ?Document $user = null;
    protected ?Document $context = null;

    /**
     * @param string $queue
     * @param string $class
     * @return void
     */
    public function __construct(string $queue, string $class)
    {
        $this->queue = $queue;
        $this->class = $class;
    }

    /**
     * Set queue used for this event.
     *
     * @param string $queue
     * @return Event
     */
    public function setQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Get queue used for this event.
     *
     * @return string
     */
    public function getQueue(): string
    {
        return $this->queue;
    }

    /**
     * Set event name used for this event.
     * @param string $event
     * @return Event
     */
    public function setEvent(string $event): self
    {
        $this->event = $event;

        return $this;
    }

    /**
     * Get event name used for this event.
     *
     * @return string
     */
    public function getEvent(): string
    {
        return $this->event;
    }

    /**
     * Set project for this event.
     *
     * @param Document $project
     * @return self
     */
    public function setProject(Document $project): self
    {
        $this->project = $project;

        return $this;
    }

    /**
     * Get project for this event.
     *
     * @return Document
     */
    public function getProject(): Document
    {
        return $this->project;
    }

    /**
     * Set user for this event.
     *
     * @param Document $user
     * @return self
     */
    public function setUser(Document $user): self
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get project for this event.
     *
     * @return Document
     */
    public function getUser(): Document
    {
        return $this->user;
    }

    /**
     * Set payload for this event.
     *
     * @param array $payload
     * @return self
     */
    public function setPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * Get payload for this event.
     *
     * @return array
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Set context for this event.
     *
     * @param Document $context
     * @return self
     */
    public function setContext(Document $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Get context for this event.
     *
     * @return null|Document
     */
    public function getContext(): ?Document
    {
        return $this->context;
    }

    /**
     * Set class used for this event.
     * @param string $class
     * @return self
     */
    public function setClass(string $class): self
    {
        $this->class = $class;

        return $this;
    }

    /**
     * Get class used for this event.
     *
     * @return string
     */
    public function getClass(): string
    {
        return $this->class;
    }

    /**
     * Set param of event.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setParam(string $key, mixed $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Get param of event.
     *
     * @param string $key
     * @return mixed
     */
    public function getParam(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }

    /**
     * Get all params of the event.
     *
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Execute Event.
     *
     * @return string|bool
     * @throws InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'user' => $this->user,
            'payload' => $this->payload,
            'context' => $this->context,
            'events' => Event::generateEvents($this->getEvent(), $this->getParams())
        ]);
    }

    /**
     * Resets event.
     *
     * @return self
     */
    public function reset(): self
    {
        $this->params = [];

        return $this;
    }

    /**
     * Parses event pattern and returns the parts in their respective section.
     *
     * @param string $pattern
     * @return array
     */
    public static function parseEventPattern(string $pattern): array
    {
        $parts = \explode('.', $pattern);
        $count = \count($parts);

        /**
         * Identify all sections of the pattern.
         */
        $type = $parts[0] ?? false;
        $resource = $parts[1] ?? false;
        $hasSubResource = $count > 3 && \str_starts_with($parts[3], '[');

        if ($hasSubResource) {
            $subType = $parts[2];
            $subResource = $parts[3];
            if ($count === 6) {
                $attribute = $parts[5];
            }
        } else {
            if ($count === 4) {
                $attribute = $parts[3];
            }
        }

        $subType ??= false;
        $subResource ??= false;
        $attribute ??= false;
        $action = match (true) {
            !$hasSubResource && $count > 2 => $parts[2],
            $hasSubResource && $count > 4 => $parts[4],
            default => false
        };

        return [
            'type' => $type,
            'resource' => $resource,
            'subType' => $subType,
            'subResource' => $subResource,
            'action' => $action,
            'attribute' => $attribute,
        ];
    }

    /**
     * Generates all possible events from a pattern.
     *
     * @param string $pattern
     * @param array $params
     * @return array
     * @throws \InvalidArgumentException
     */
    public static function generateEvents(string $pattern, array $params = []): array
    {
        // $params = \array_filter($params, fn($param) => !\is_array($param));
        $paramKeys = \array_keys($params);
        $paramValues = \array_values($params);

        $patterns = [];

        $parsed = self::parseEventPattern($pattern);
        $type = $parsed['type'];
        $resource = $parsed['resource'];
        $subType = $parsed['subType'];
        $subResource = $parsed['subResource'];
        $action = $parsed['action'];
        $attribute = $parsed['attribute'];

        if ($resource && !\in_array(\trim($resource, "\[\]"), $paramKeys)) {
            throw new InvalidArgumentException("{$resource} is missing from the params.");
        }

        if ($subResource && !\in_array(\trim($subResource, "\[\]"), $paramKeys)) {
            throw new InvalidArgumentException("{$subResource} is missing from the params.");
        }

        /**
         * Create all possible patterns including placeholders.
         */
        if ($action) {
            if ($subResource) {
                if ($attribute) {
                    $patterns[] = \implode('.', [$type, $resource, $subType, $subResource, $action, $attribute]);
                }
                $patterns[] = \implode('.', [$type, $resource, $subType, $subResource, $action]);
                $patterns[] = \implode('.', [$type, $resource, $subType, $subResource]);
            } else {
                $patterns[] = \implode('.', [$type, $resource, $action]);
            }
            if ($attribute) {
                $patterns[] = \implode('.', [$type, $resource, $action, $attribute]);
            }
        }
        if ($subResource) {
            $patterns[] = \implode('.', [$type, $resource, $subType, $subResource]);
        }
        $patterns[] = \implode('.', [$type, $resource]);

        /**
         * Removes all duplicates.
         */
        $patterns = \array_unique($patterns);

        /**
         * Set all possible values of the patterns and replace placeholders.
         */
        $events = [];
        foreach ($patterns as $eventPattern) {
            $events[] = \str_replace($paramKeys, $paramValues, $eventPattern);
            $events[] = \str_replace($paramKeys, '*', $eventPattern);
            foreach ($paramKeys as $key) {
                foreach ($paramKeys as $current) {
                    if ($current === $key) {
                        continue;
                    }

                    $filtered = \array_filter($paramKeys, fn(string $k) => $k === $current);
                    $events[] = \str_replace($paramKeys, $paramValues, \str_replace($filtered, '*', $eventPattern));
                }
            }
        }

        /**
         * Remove [] from the events.
         */
        $events = \array_map(fn (string $event) => \str_replace(['[', ']'], '', $event), $events);
        $events = \array_unique($events);

        return $events;
    }
}
