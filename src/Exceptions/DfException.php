<?php
namespace DreamFactory\Core\Exceptions;

/**
 * DfException
 */
class DfException extends \Exception
{
    //*************************************************************************
    //* Members
    //*************************************************************************

    /**
     * @var mixed
     */
    protected $context = null;

    //*************************************************************************
    //* Methods
    //*************************************************************************

    /**
     * Constructs a exception.
     *
     * @param mixed $message
     * @param int   $code
     * @param mixed $previous
     * @param mixed $context Additional information for downstream consumers
     */
    public function __construct($message = null, $code = null, $previous = null, $context = null)
    {
        //	If an exception is passed in, translate...
        if (null === $code && $message instanceof \Exception) {
            $context = $code;

            $exception = $message;
            $message = $exception->getMessage();
            $code = $exception->getCode();
            $previous = $exception->getPrevious();
        }

        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    /**
     * Return a code/message combo when printed.
     *
     * @return string
     */
    public function __toString()
    {
        return '[' . $this->getCode() . '] ' . $this->getMessage();
    }

    /**
     * Get the additional context information.
     *
     * @return mixed
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Set or override the additional context information.
     *
     * @param mixed $context
     *
     * @return mixed
     */
    public function setContext($context = null)
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Set or override the message information.
     *
     * @param mixed $message
     *
     * @return mixed
     */
    public function setMessage($message = null)
    {
        $this->message = $message;

        return $this;
    }
}
