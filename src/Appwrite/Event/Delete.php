<?php

namespace Appwrite\Event;

use Resque;
use Utopia\Database\Document;

class Delete extends Event
{
    protected string $type = '';
    protected ?int $timestamp = null;
    protected ?int $timestamp1d = null;
    protected ?int $timestamp30m = null;
    protected ?Document $document = null;

    public function __construct()
    {
        parent::__construct(Event::DELETE_QUEUE_NAME, Event::DELETE_CLASS_NAME);
    }

    /**
     * Sets the type for the delete event (use the constants starting with DELETE_TYPE_*).
     *
     * @param string $type
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Returns the set type for the delete event.
     *
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set timestamp.
     *
     * @param int $timestamp
     * @return self
     */
    public function setTimestamp(int $timestamp): self
    {
        $this->timestamp = $timestamp;

        return $this;
    }

    /**
     * Set timestamp for 1 day interval.
     *
     * @param int $timestamp
     * @return self
     */
    public function setTimestamp1d(int $timestamp): self
    {
        $this->timestamp1d = $timestamp;

        return $this;
    }

    /**
     * Sets timestamp for 30m interval.
     *
     * @param int $timestamp
     * @return self
     */
    public function setTimestamp30m(int $timestamp): self
    {
        $this->timestamp30m = $timestamp;

        return $this;
    }

    /**
     * Sets the document for the delete event.
     *
     * @param Document $document
     * @return self
     */
    public function setDocument(Document $document): self
    {
        $this->document = $document;

        return $this;
    }

    /**
     * Returns the set document for the delete event.
     *
     * @return null|Document
     */
    public function getDocument(): ?Document
    {
        return $this->document;
    }

    /**
     * Executes this event and sends it to the deletes worker.
     *
     * @return string|bool
     * @throws \InvalidArgumentException
     */
    public function trigger(): string|bool
    {
        return Resque::enqueue($this->queue, $this->class, [
            'project' => $this->project,
            'type' => $this->type,
            'document' => $this->document,
            'timestamp' => $this->timestamp,
            'timestamp1d' => $this->timestamp1d,
            'timestamp30m' => $this->timestamp30m
        ]);
    }
}
