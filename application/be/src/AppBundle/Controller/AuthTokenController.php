<?php
namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use FOS\RestBundle\Controller\Annotations as Rest; 
use AppBundle\Form\Type\CredentialsType;
use AppBundle\Entity\AuthToken;
use AppBundle\Entity\Credentials;

class AuthTokenController extends Controller
{
    /**
     * @Rest\View(statusCode=Response::HTTP_CREATED, serializerGroups={"auth-token"})
     * @Rest\Post("/auth-tokens")
     */
    public function postAuthTokensAction(Request $request)
    {
        $credentials = new Credentials();
        $form = $this->createForm(CredentialsType::class, $credentials);

        $form->submit($request->request->all());

        if (!$form->isValid()) {
            $authToken = $form;
        } else {
            $em = $this->get('doctrine.orm.entity_manager');

            $user = $em->getRepository('AppBundle:User')
                ->findOneByEmail($credentials->getLogin());

            // Bad user
            if (!$user) {
                $authToken = $this->invalidCredentials();
            } else {
                $encoder = $this->get('security.password_encoder');
                $isPasswordValid = $encoder->isPasswordValid($user, $credentials->getPassword());

                // Bad password
                if (!$isPasswordValid) { 
                    $authToken = $this->invalidCredentials();
                } else {
                    $authToken = new AuthToken();
                    $authToken->setValue($this->get('lexik_jwt_authentication.encoder')
                        ->encode([
                            'username' => $user->getUsername(),
                            'exp' => time() + $this->getParameter('jwt_token_ttl')
                        ])
                    );
                    $authToken->setCreatedDate(new \DateTime('now'));
                    $authToken->setUser($user);

                    $em->persist($authToken);
                    $em->flush();
                }
            }
        }

        return $authToken;
    }

    /**
     * @Rest\View(statusCode=Response::HTTP_NO_CONTENT)
     * @Rest\Delete("/auth-tokens/{id}")
     */
    public function removeAuthTokenAction(Request $request)
    {
        $em = $this->get('doctrine.orm.entity_manager');
        $authToken = $em->getRepository('AppBundle:AuthToken')
                    ->find($request->get('id'));

        $connectedUser = $this->get('security.token_storage')->getToken()->getUser();

        if ($authToken && $authToken->getUser()->getId() === $connectedUser->getId()) {
            $em->remove($authToken);
            $em->flush();
        } else {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException();
        }
    }

    private function invalidCredentials()
    {
        return \FOS\RestBundle\View\View::create(['message' => 'Invalid credentials'], Response::HTTP_BAD_REQUEST);
    }
}