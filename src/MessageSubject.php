<?php

namespace Rx\Websocket;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\FrameInterface;
use Ratchet\RFC6455\Messaging\Message;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use Ratchet\RFC6455\Messaging\MessageInterface;
use Rx\Observable;
use Rx\ObserverInterface;
use Rx\Subject\Subject;

class MessageSubject extends Subject
{
    protected $rawDataIn;
    protected $rawDataOut;
    protected $mask;
    protected $controlFrames;
    private $subProtocol;
    private $request;
    private $response;
    private $rawDataDisp;

    public function __construct(
        Observable $rawDataIn,
        ObserverInterface $rawDataOut,
        bool $mask = false,
        bool $useMessageObject = false, 
        $subProtocol = "",
        RequestInterface $request,
        ResponseInterface $response
    ) {
        $this->request     = $request;
        $this->response    = $response;
        $this->rawDataIn   = $rawDataIn;
        $this->rawDataOut  = $rawDataOut;
        $this->mask        = $mask;
        $this->subProtocol = $subProtocol;

        $messageBuffer = new MessageBuffer(
            new CloseFrameChecker(),
            function (MessageInterface $msg) use ($useMessageObject) {
                parent::onNext($useMessageObject ? $msg : $msg->getPayload());
            },
            function (FrameInterface $frame) use ($rawDataOut) {
                switch ($frame->getOpcode()) {
                    case Frame::OP_PING:
                        $this->sendFrame(new Frame($frame->getPayload(), true, Frame::OP_PONG));
                        return;
                    case Frame::OP_CLOSE:
                        // send close frame to remote
                        $this->sendFrame($frame);

                        // get close code
                        list($closeCode) = array_merge(unpack('n*', substr($frame->getPayload(), 0, 2)));
                        if ($closeCode !== 1000) {
                            // emit close code as error
                            $exception = new WebsocketErrorException($closeCode);
                            parent::onError($exception);
                        }

                        // complete output stream
                        $rawDataOut->onCompleted();

                        // signal subscribers that we are done here
                        //parent::onCompleted();
                        return;
                }
            },
            !$this->mask
        );

        $this->rawDataDisp = $this->rawDataIn->subscribe(
            function ($data) use ($messageBuffer) {
                $messageBuffer->onData($data);
            },
            function (\Exception $exception) {
                parent::onError($exception);
            },
            function () {
                parent::onCompleted();
            });

        $this->subProtocol = $subProtocol;
    }

    private function createCloseFrame(int $closeCode = Frame::CLOSE_NORMAL): Frame
    {
        $frame = new Frame(pack('n', $closeCode), true, Frame::OP_CLOSE);
        if ($this->mask) {
            $frame->maskPayload();
        }
        return $frame;
    }

    public function send($value)
    {
        $this->onNext($value);
    }

    public function sendFrame(Frame $frame)
    {
        if ($this->mask) {
            $this->rawDataOut->onNext($frame->maskPayload()->getContents());
            return;
        }

        $this->rawDataOut->onNext($frame->getContents());
    }

    public function getControlFrames(): Observable
    {
        return $this->controlFrames;
    }

    // The ObserverInterface is commandeered by this class. We will use the parent:: stuff ourselves for notifying
    // subscribers
    public function onNext($value)
    {
        if ($value instanceof Message) {
            $this->sendFrame(new Frame($value, true, $value->isBinary() ? Frame::OP_BINARY : Frame::OP_TEXT));
            return;
        }
        $this->sendFrame(new Frame($value));
    }

    public function onError(\Throwable $exception)
    {
        $this->rawDataDisp->dispose();

        parent::onError($exception);
    }

    public function onCompleted()
    {
        $this->sendFrame($this->createCloseFrame());

        parent::onCompleted();
    }

    public function getSubProtocol(): string
    {
        return $this->subProtocol;
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
