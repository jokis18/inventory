<?php

namespace App\Controller;

use App\Model\User;
use App\Model\Queue;
use App\Model\Shop;
use App\Model\Template;
use PhpAmqpLib\Message\AMQPMessage;

class Products
{
    protected $view;
    protected $flash;
    protected $rabbit;
    protected $s3;

    public function __construct($view, $flash, $rabbit)
    {
        $this->view = $view;
        $this->flash = $flash;
        $this->rabbit = $rabbit;
        $credentials = new \Aws\Credentials\Credentials(getenv("AWS_ACCESS_KEY"),getenv("AWS_ACCESS_SECRET"));
        $this->s3 = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => $credentials
        ]);
    }

    public function show_form($request, $response, $arguments)
    {
        $user = User::find($request->getAttribute('user')->id);
        $shops = $user->shops;
        $templates = Template::with('sub_templates')->get();

        return $this->view->render($response, 'product.html', array(
            'user' => $user,
            'shops' => $shops,
            'templates' => $templates
        ));
    }

    public function queue($request, $response, $arguments)
    {
        $queue = Queue::orderBy('created_at', 'desc')->take(250)->get();
        foreach ($queue as $record) {
            // TODO: Move shop_id to table column
            $data = $record->data;
            $shop = Shop::find($record->shop);
            $record->shop = $shop;
        }
        return $this->view->render($response, 'queue.html', array(
            'queue' => $queue
        ));
    }

    public function create($request, $response, $arguments)
    {
        $post = $request->getParsedBody();
        $start = time();
        $files = $request->getUploadedFiles();

        $hash = hash('sha256', uniqid(true));
        $path = "/tmp/{$hash}";

        $file = $_FILES['zip_file'];
        $passedFileName = $file['name'];
        $tmpName = $file['tmp_name'];
        $fileName = hash('sha256', uniqid(true));


        $zip = new \ZipArchive();
        $zip->open($tmpName);
        $zip->extractTo($path);

        $objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

        foreach($objects as $name => $object) {
            $name = str_replace('/tmp/','',$name);
            $this->s3->putObject([
                'Bucket' => "shopify-product-importer",
                'SourceFile' => $object,
                'ACL' => "public-read",
                'Key' => str_replace(' ', '_', $name),
                'Content-Type' => 'application/zip_file'
            ]);
        }
        $shopId = $post['shop'];
        $shop = Shop::find($shopId);
        if (empty($shop)) {
            $this->flash->addMessage('error', "We couldnt find that shop");
            return $response->withRedirect('/products');
        }

        $data = array(
            'file' => $hash,
            'post' => $request->getParsedBody(),
            'file_name' => $passedFileName
        );
        $data['post']['shop'] = $shopId;
        $queue = new Queue();
        $queue->data = $data;
        $queue->status = Queue::PENDING;
        $queue->shop_id = $shopId;
        $queue->file_name = $data['file'];
        $queue->template_id = $data['post']['template'];
        $queue->log_to_google = (int) $data['post']['log_to_google'];
        $queue->vendor = $post['vendor'];
        $queue->product_type = $post['product_type'];
        $queue->title = $post['product_title'];
        $queue->file = $data['file_name'];
        $queue->tags = $post['tags'];
        $queue->showcase_color = $post['default_color'];
        $queue->showcase_product = $post['default_product'];
        $queue->print_type = $post['print_type'];
        $queue->sub_template_id = $post['sub_template'];
        $queue->save();

        $elapsed_time = time() - $start;
        $this->flash->addMessage("message", "Product successfully added to queue.");
        return $response->withRedirect('/products');
    }

    protected function createBatchProduct($request, $response, $arguments)
    {
        $file = $_FILES['zip_file'];
        $passedFileName = $file['name'];
        $hash = hash('sha256', uniqid(true));
        $path = "/tmp/{$hash}";

        $zip = new \ZipArchive();
        $zip->open($file['tmp_name']);
        $zip->extractTo($path);

        $shopId = $_POST['shop'];
        $shop = Shop::find($shopId);

        if (empty($shop)) {
            $this->flash->addMessage('error', "We couldnt find that shop");
            return $response->withRedirect('/products');
        }
        $objects = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        $i = 0;
        foreach ($objects as $object) {
            if ($object->getExtension() === 'zip') {
                $i++;
                $zip = new \ZipArchive();
                $zip->open($object->getRealPath());
                $newPath = $path.'/'.$object->getBasename('zip');
                $zip->extractTo($newPath);
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($newPath));
                foreach ($files as $name => $file) {
                    if (!in_array($file->getExtension(), ['jpg', 'png'])) {
                        continue;
                    }
                    $name = str_replace('/tmp/', '', $name);
                    $this->s3->putObject([
                        'Bucket' => "shopify-product-importer",
                        'SourceFile' => $file,
                        'ACL' => 'public-read',
                        'Key' => str_replace(' ', '_', $name).'-'.$i,
                        'Content-Type' => 'application/zip_file'
                    ]);

                    $data = array(
                        'file' => $hash,
                        'post' => $request->getParsedBody(),
                        'file_name' => $passedFileName
                    );
                    $data['post']['shop'] = $shopId;
                    $queue = new Queue();
                    $queue->data = $data;
                    $queue->status = Queue::PENDING;
                    $queue->shop = $shopId;
                    $queue->file_name = $data['file'];
                    $queue->template = $data['post']['template'];
                    $queue->log_to_google = (int) $data['post']['log_to_google'];
                    $queue->save();
                }
            }
        }
    }

    protected function createSingleProduct($request, $response, $arguments)
    {

    }

    public function restart_queue($request, $response, $args)
    {
        $post = $request->getParsedBody();
        $queue_id = $post['queue_id'];
        $queue = Queue::find($queue_id);
        $queue->status = Queue::PENDING;
        $queue->save();
        $this->flash->addMessage("message", "Queued product successfully restarted");
        return $response->withRedirect('/queue');
    }
}
