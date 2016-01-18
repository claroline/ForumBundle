<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Claroline\ForumBundle\Controller;

use Claroline\ForumBundle\Entity\Message;
use Claroline\ForumBundle\Entity\Subject;
use Claroline\ForumBundle\Entity\Forum;
use Claroline\ForumBundle\Entity\Category;
use Claroline\ForumBundle\Form\MessageType;
use Claroline\ForumBundle\Form\SubjectType;
use Claroline\ForumBundle\Form\CategoryType;
use Claroline\ForumBundle\Form\EditTitleType;
use Claroline\ForumBundle\Event\Log\ReadSubjectEvent;
use Claroline\CoreBundle\Library\Resource\ResourceCollection;
use Claroline\CoreBundle\Entity\Workspace\Workspace;
use Claroline\CoreBundle\Entity\User;
use Claroline\ForumBundle\Manager\Manager;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Form\FormError;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration as EXT;
use JMS\DiExtraBundle\Annotation as DI;

/**
 * ForumController
 */
class ForumController extends Controller
{
    private $manager;
    private $tokenStorage;
    private $authorization;

    /**
     * Constructor.
     *
     * @DI\InjectParams({
     *     "manager"       = @DI\Inject("claroline.manager.forum_manager"),
     *     "authorization" = @DI\Inject("security.authorization_checker"),
     *     "tokenStorage"  = @DI\Inject("security.token_storage")
     * })
     */
    public function __construct(
        Manager $manager,
        TokenStorageInterface $tokenStorage,
        AuthorizationCheckerInterface $authorization
    )
    {
        $this->manager = $manager;
        $this->tokenStorage = $tokenStorage;
        $this->authorization = $authorization;
    }
    /**
     * @Route(
     *     "/{forum}/category",
     *     name="claro_forum_categories",
     *     defaults={"page"=1}
     * )
     * @Template("ClarolineForumBundle::index.html.twig")
     *
     * @param Forum $forum
     * @param User $user
     */
    public function openAction(Forum $forum)
    {
        $em = $this->getDoctrine()->getManager();
        $this->checkAccess($forum);
        $categories = $em->getRepository('ClarolineForumBundle:Forum')->findCategories($forum);
        $user = $this->tokenStorage->getToken()->getUser();
        $hasSubscribed = $user === 'anon.' ?
            false :
            $this->manager->hasSubscribed($user, $forum);
        $isModerator = $this->authorization->isGranted(
            'moderate',
            new ResourceCollection(array($forum->getResourceNode()))
        ) && $user !== 'anon.';

        return array(
            'search' => null,
            '_resource' => $forum,
            'isModerator' => $isModerator,
            'categories' => $categories,
            'hasSubscribed' => $hasSubscribed,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/category/{category}/subjects/page/{page}/max/{max}",
     *     name="claro_forum_subjects",
     *     defaults={"page"=1, "max"=20},
     *     options={"expose"=true}
     * )
     * @Template()
     *
     * @param Category $category
     * @param integer $page
     * @param integer $max
     */
    public function subjectsAction(Category $category, $page, $max)
    {
        $forum = $category->getForum();
        $this->checkAccess($forum);
        $pager = $this->manager->getSubjectsPager($category, $page, $max);

        $subjectsIds = array();
        $lastMessages = array();

        foreach ($pager as $subject) {
            $subjectsIds[] = $subject['id'];
        }
        $messages = $this->manager->getLastMessagesBySubjectsIds($subjectsIds);

        foreach ($messages as $message) {
            $lastMessages[$message->getSubject()->getId()] = $message;
        }
        $collection = new ResourceCollection(array($forum->getResourceNode()));
        $isAnon = $this->isAnon();
        $canCreateSubject = $this->authorization->isGranted('post', $collection);
        $isModerator = $this->authorization->isGranted('moderate', $collection) &&
            !$isAnon;

        $logs = array();

        if (!$isAnon) {
            $securityToken = $this->tokenStorage->getToken();

            if (!is_null($securityToken)) {
                $user = $securityToken->getUser();
                $logs = $this->manager->getSubjectsReadingLogs($user, $forum->getResourceNode());
            }
        }
        $lastAccessDates = array();

        foreach ($logs as $log) {
            $details = $log->getDetails();
            $subjectId = $details['subject']['id'];

            if (!isset($lastAccessDates[$subjectId])) {
                $lastAccessDates[$subjectId] = $log->getDateLog();
            }
        }

        return array(
            'pager' => $pager,
            '_resource' => $forum,
            'canCreateSubject' => $canCreateSubject,
            'isModerator' => $isModerator,
            'category' => $category,
            'max' => $max,
            'lastMessages' => $lastMessages,
            'workspace' => $forum->getResourceNode()->getWorkspace(),
            'lastAccessDates' => $lastAccessDates,
            'isAnon' => $isAnon
        );
    }

    /**
     * @Route(
     *     "/form/subject/{category}",
     *     name="claro_forum_form_subject_creation"
     * )
     * @ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @Template()
     *
     * @param Category $category
     */
    public function subjectFormAction(Category $category)
    {
        $forum = $category->getForum();
        $collection = new ResourceCollection(array($forum->getResourceNode()));

        if (!$this->authorization->isGranted('post', $collection)) {
            throw new AccessDeniedException($collection->getErrorsForDisplay());
        }

        $formSubject = $this->get('form.factory')->create(new SubjectType());

        return array(
            '_resource' => $forum,
            'form' => $formSubject->createView(),
            'category' => $category,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/form/category/{forum}",
     *     name="claro_forum_form_category_creation"
     * )
     * @ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @Template()
     *
     * @param Forum $forum
     */
    public function categoryFormAction(Forum $forum)
    {
        $collection = new ResourceCollection(array($forum->getResourceNode()));

        if (!$this->authorization->isGranted('post', $collection)) {
            throw new AccessDeniedException($collection->getErrorsForDisplay());
        }

        $formCategory = $this->get('form.factory')->create(new CategoryType());

        return array(
            '_resource' => $forum,
            'form' => $formCategory->createView(),
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/category/create/{forum}",
     *     name="claro_forum_create_category"
     * )
     * @ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @Template()
     * @param Forum $forum
     */
    public function createCategoryAction(Forum $forum)
    {
        $collection = new ResourceCollection(array($forum->getResourceNode()));

        if (!$this->authorization->isGranted('post', $collection)) {
            throw new AccessDeniedException($collection->getErrorsForDisplay());
        }

        $form = $this->get('form.factory')->create(new CategoryType(), new Category());
        $form->handleRequest($this->get('request'));

        if ($form->isValid()) {
            $category = $form->getData();
            $this->manager->createCategory($forum, $category->getName());
        }

        return new RedirectResponse(
            $this->generateUrl('claro_forum_categories', array('forum' => $forum->getId()))
        );
    }

    /**
     * The form submission is working but I had to do some weird things to make it works.
     *
     * @Route(
     *     "/subject/create/{category}",
     *     name="claro_forum_create_subject"
     * )
     * @ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     * @Template("ClarolineForumBundle:Forum:subjectForm.html.twig")
     * @param Category $category
     * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
     * @throws \Exception
     * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function createSubjectAction(Category $category)
    {
        $forum = $category->getForum();
        $collection = new ResourceCollection(array($forum->getResourceNode()));

        if (!$this->authorization->isGranted('post', $collection)) {
            throw new AccessDeniedException($collection->getErrorsForDisplay());
        }

        $form = $this->get('form.factory')->create(new SubjectType(), new Subject);
        $form->handleRequest($this->get('request'));

        if ($form->isValid()) {
            $user = $this->tokenStorage->getToken()->getUser();
            $subject = $form->getData();
            $subject->setCreator($user);
            $subject->setAuthor($user->getFirstName() . ' ' . $user->getLastName());
            //instantiation of the new resources
            $subject->setCategory($category);
            $this->manager->createSubject($subject);
            $dataMessage = $form->get('message')->getData();

            if ($dataMessage['content'] !== null) {
                $message = new Message();
                $message->setContent($dataMessage['content']);
                $message->setCreator($user);
                $message->setAuthor($user->getFirstName() . ' ' . $user->getLastName());
                $message->setSubject($subject);
                $this->manager->createMessage($message, $subject);

                return new RedirectResponse(
                    $this->generateUrl('claro_forum_subjects', array('category' => $category->getId()))
                );
            }
        }

        $form->get('message')->addError(
            new FormError($this->get('translator')->trans('field_content_required', array(), 'forum'))
        );

        return array(
            'form' => $form->createView(),
            '_resource' => $forum,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/subject/{subject}/messages/page/{page}/max/{max}",
     *     name="claro_forum_messages",
     *     defaults={"page"=1, "max"= 20},
     *     options={"expose"=true}
     * )
     * @Template()
     *
     * @param Subject $subject
     * @param integer $page
     * @param integer $max
     */
    public function messagesAction(Subject $subject, $page, $max)
    {
        $forum = $subject->getCategory()->getForum();
        $this->checkAccess($forum);
        $isAnon = $this->isAnon();
        $isModerator = $this->authorization->isGranted(
            'moderate',
            new ResourceCollection(array($forum->getResourceNode()))
        ) && !$isAnon;
        $pager = $this->manager->getMessagesPager($subject, $page, $max);
        $collection = new ResourceCollection(array($forum->getResourceNode()));
        $canPost = $this->authorization->isGranted('post', $collection);
        $form = $this->get('form.factory')->create(new MessageType());

        if (!$isAnon) {
            $securityToken = $this->tokenStorage->getToken();

            if (!is_null($securityToken)) {
                $user = $securityToken->getUser();
                $event = new ReadSubjectEvent($subject);
                $event->setDoer($user);
                $this->dispatch($event);
            }
        }

        return array(
            'subject' => $subject,
            'pager' => $pager,
            '_resource' => $forum,
            'isModerator' => $isModerator,
            'form' => $form->createView(),
            'category' => $subject->getCategory(),
            'max' => $max,
            'canPost' => $canPost,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/create/message/{subject}",
     *     name="claro_forum_create_message"
     * )
     * @ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     *
     * @param Subject $subject
     */
    public function createMessageAction(Subject $subject)
    {
        $form = $this->container->get('form.factory')->create(new MessageType, new Message());
        $form->handleRequest($this->get('request'));


        if ($form->isValid()) {
            $message = $form->getData();
            $this->manager->createMessage($message, $subject);
        }

        return new RedirectResponse(
            $this->generateUrl('claro_forum_messages', array('subject' => $subject->getId()))
        );
    }

    /**
     * @Route(
     *     "/edit/message/{message}/form",
     *     name="claro_forum_edit_message_form"
     * )
     * @Template()
     * @param Message $message
     */
    public function editMessageFormAction(Message $message)
    {
        $subject = $message->getSubject();
        $forum = $subject->getCategory()->getForum();
        $isModerator = $this->authorization->isGranted('moderate', new ResourceCollection(array($forum->getResourceNode())));

        if (!$isModerator && $this->tokenStorage->getToken()->getUser() !== $message->getCreator()) {
            throw new AccessDeniedException();
        }

        $form = $this->get('form.factory')->create(new MessageType(), $message);

        return array(
            'subject' => $subject,
            'form' => $form->createView(),
            'message' => $message,
            '_resource' => $forum,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/edit/message/{message}",
     *     name="claro_forum_edit_message"
     * )
     *
     * @Template("ClarolineForumBundle:Forum:editMessageForm.html.twig")
     * @param Message $message
     */
    public function editMessageAction(Message $message)
    {
        $subject = $message->getSubject();
        $forum = $subject->getCategory()->getForum();
        $isModerator = $this->authorization->isGranted('moderate', new ResourceCollection(array($forum->getResourceNode())));

        if (!$isModerator && $this->tokenStorage->getToken()->getUser() !== $message->getCreator()) {
            throw new AccessDeniedException();
        }

        $oldContent = $message->getContent();
        $form = $this->container->get('form.factory')->create(new MessageType, new Message());
        $form->handleRequest($this->get('request'));

        if ($form->isValid()) {
            $newContent = $form->get('content')->getData();
            $this->manager->editMessage($message, $oldContent, $newContent);

            return new RedirectResponse(
                $this->generateUrl('claro_forum_messages', array('subject' => $subject->getId()))
            );
        }

        return array(
            'subject' => $subject,
            'form' => $form->createView(),
            'message' => $message,
            '_resource' => $forum,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/edit/category/{category}/form",
     *     name="claro_forum_edit_category_form"
     * )
     * @Template()
     * @param Category $category
     */
    public function editCategoryFormAction(Category $category)
    {
        $forum = $category->getForum();
        $isModerator = $this->authorization->isGranted('moderate', new ResourceCollection(array($forum->getResourceNode())));

        if (!$isModerator && $this->tokenStorage->getToken()->getUser()) {
            throw new AccessDeniedException();
        }

        $form = $this->container->get('form.factory')->create(new CategoryType, $category);
        $form->handleRequest($this->get('request'));

        return array(
            'category' => $category,
            'form' => $form->createView(),
            '_resource' => $forum,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/edit/category/{category}",
     *     name="claro_forum_edit_category"
     * )
     * @param Category $category
     */
    public function editCategoryAction(Category $category)
    {
        $forum = $category->getForum();
        $isModerator = $this->authorization->isGranted('moderate', new ResourceCollection(array($forum->getResourceNode())));

        if (!$isModerator && $this->tokenStorage->getToken()->getUser()) {
            throw new AccessDeniedException();
        }

        $oldName = $category->getName();
        $form = $this->container->get('form.factory')->create(new CategoryType, $category);
        $form->handleRequest($this->get('request'));

        if ($form->isValid()) {
            $newName = $form->get('name')->getData();
            $this->manager->editCategory($category, $oldName, $newName);

            return new RedirectResponse(
                $this->generateUrl('claro_forum_categories', array('forum' => $forum->getId()))
            );
        }
    }

    /**
     * @Route(
     *     "/delete/category/{category}",
     *     name="claro_forum_delete_category"
     * )
     *
     * @param Category $category
     */
    public function deleteCategory(Category $category)
    {
        $forum = $category->getForum();

        if ($this->authorization->isGranted('moderate', new ResourceCollection(array($category->getForum()->getResourceNode())))) {

            $this->manager->deleteCategory($category);

            return new RedirectResponse(
                $this->generateUrl('claro_forum_categories', array('forum' => $forum->getId()))
            );
        }

        throw new AccessDeniedException();
    }

    /**
     * @Route(
     *     "/{forum}/search/{search}/page/{page}",
     *     name="claro_forum_search",
     *     defaults={"page"=1, "search"= ""},
     *     options={"expose"=true}
     * )
     * @Template("ClarolineForumBundle::searchResults.html.twig")
     * @param Forum $forum
     * @param integer $page
     * @param string $search
     */
    public function searchAction(Forum $forum, $page, $search)
    {
        $pager = $this->manager->searchPager($forum, $search, $page);

        return array(
            'pager' => $pager,
            '_resource' => $forum,
            'search' => $search,
            'page' => $page,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

     /**
     * @Route(
     *     "/edit/subject/{subjectId}/form",
     *     name="claro_forum_edit_subject_form"
     * )
     * @ParamConverter(
     *      "subject",
     *      class="ClarolineForumBundle:Subject",
     *      options={"id" = "subjectId", "strictId" = true}
     * )
     * @Template()
     * @param Subject $subject
     */
    public function editSubjectFormAction(Subject $subject)
    {
        $forum = $subject->getCategory()->getForum();
        $isModerator = $this->authorization->isGranted('moderate', new ResourceCollection(array($forum->getResourceNode())));

        if (!$isModerator && $this->tokenStorage->getToken()->getUser() !== $subject->getCreator()) {
            throw new AccessDeniedException();
        }

        $form = $this->container->get('form.factory')->create(new EditTitleType(), $subject);

        return array(
            'form' => $form->createView(),
            'subject' => $subject,
            'forumId' => $forum->getId(),
            '_resource' => $forum,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/edit/subject/{subjectId}/submit",
     *     name="claro_forum_edit_subject"
     * )
     * @ParamConverter(
     *      "subject",
     *      class="ClarolineForumBundle:Subject",
     *      options={"id" = "subjectId", "strictId" = true}
     * )
     * @Template("ClarolineForumBundle:Forum:editSubjectForm.html.twig")
     * @param Subject $subject
     */
    public function editSubjectAction(Subject $subject)
    {
        $forum = $subject->getCategory()->getForum();
        $isModerator = $this->authorization->isGranted(
            'moderate', new ResourceCollection(array($forum->getResourceNode()))
        );

        if (!$isModerator && $this->tokenStorage->getToken()->getUser() !== $subject->getCreator()) {
            throw new AccessDeniedException();
        }

        $oldTitle = $subject->getTitle();
        $form = $this->container->get('form.factory')->create(new EditTitleType(), $subject);
        $form->handleRequest($this->get('request'));

        if ($form->isValid()) {
            $newTitle = $form->get('title')->getData();
            $this->manager->editSubject($subject, $oldTitle, $newTitle);

            return new RedirectResponse(
                $this->generateUrl('claro_forum_subjects', array('category' => $subject->getCategory()->getId()))
            );
        }

        return array(
            'form' => $form->createView(),
            'subjectId' => $subject->getId(),
            'forumId' => $forum->getId(),
            '_resource' => $forum,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/delete/message/{message}",
     *     name="claro_forum_delete_message"
     * )
     *
     * @param \Claroline\ForumBundle\Entity\Message $message
     */
    public function deleteMessageAction(Message $message)
    {
        if ($this->authorization->isGranted('moderate', new ResourceCollection(array($message->getSubject()->getCategory()->getForum()->getResourceNode())))) {
            $this->manager->deleteMessage($message);

            return new RedirectResponse(
                $this->generateUrl('claro_forum_messages', array('subject' => $message->getSubject()->getId()))
            );
        }

        throw new AccessDeniedException();
    }

    /**
     * @Route(
     *     "/subscribe/forum/{forum}",
     *     name="claro_forum_subscribe"
     * )
     * @EXT\ParamConverter("user", options={"authenticatedUser" = true})
     *
     * @param Forum $forum
     * @param User $user
     */
    public function subscribeAction(Forum $forum, User $user)
    {
        $this->manager->subscribe($forum, $user);

        return new RedirectResponse(
            $this->generateUrl('claro_forum_categories', array('forum' => $forum->getId()))
        );
    }

    /**
     * @Route(
     *     "/unsubscribe/forum/{forum}",
     *     name="claro_forum_unsubscribe"
     * )
     * @EXT\ParamConverter("user", options={"authenticatedUser" = true})
     *
     * @param Forum $forum
     * @param User $user
     */
    public function unsubscribeAction(Forum $forum, User $user)
    {
        $this->manager->unsubscribe($forum, $user);

        return new RedirectResponse(
            $this->generateUrl('claro_forum_categories', array('forum' => $forum->getId()))
        );
    }

    /**
     * @Route(
     *     "/delete/subject/{subject}",
     *     name="claro_forum_delete_subject"
     * )
     *
     * @param Subject $subject
     */
    public function deleteSubjectAction(Subject $subject)
    {
        if ($this->authorization->isGranted('moderate', new ResourceCollection(array($subject->getCategory()->getForum()->getResourceNode())))) {

            $this->manager->deleteSubject($subject);

            return new RedirectResponse(
                $this->generateUrl('claro_forum_subjects', array('category' => $subject->getCategory()->getId()))
            );
        }

        throw new AccessDeniedException();
    }

    /**
     * @param \Claroline\ForumBundle\Entity\Forum $forum
     * @throws AccessDeniedHttpException
     */
    private function checkAccess(Forum $forum)
    {
        $collection = new ResourceCollection(array($forum->getResourceNode()));

        if (!$this->authorization->isGranted('OPEN', $collection)) {
            throw new AccessDeniedException($collection->getErrorsForDisplay());
        }
    }

    protected function dispatch($event)
    {
        $this->get('event_dispatcher')->dispatch('log', $event);

        return $this;
    }

    /**
     * @EXT\Route(
     *     "/subject/{subject}/move/form",
     *     name="claro_subject_move_form",
     *     options={"expose"=true}
     * )
     * @EXT\Method("GET")
     * @EXT\Template()
     * @param Subject $subject
     */
    public function moveSubjectFormAction(Subject $subject)
    {
        $category = $subject->getCategory();
        $forum = $category->getForum();
        $this->checkAccess($forum);
        $categories = $forum->getCategories();

        return array(
            '_resource' => $forum,
            'categories' => $categories,
            'category' => $category,
            'subject' => $subject,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @EXT\Route(
     *     "/message/{message}/move/form/page/{page}",
     *     name="claro_message_move_form",
     *     options={"expose"=true},
     *     defaults={"page"=1}
     * )
     * @EXT\Method("GET")
     * @EXT\Template()
     * @param Message $message
     * @param integer $page
     */
    public function moveMessageFormAction(Message $message, $page)
    {
        $subject = $message->getSubject();
        $category = $subject->getCategory();
        $forum = $category->getForum();
        $this->checkAccess($forum);
        $pager = $this->manager->getSubjectsPager($category, $page);

        return array(
            '_resource' => $forum,
            'category' => $category,
            'subject' => $subject,
            'pager' => $pager,
            'message' => $message,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @EXT\Route(
     *     "/message/{message}/move/{newSubject}",
     *     name="claro_message_move",
     *     options={"expose"=true}
     * )
     * @EXT\Method("GET")
     *
     * @param Message $message
     * @param Subject $newSubject
     */
    public function moveMessageAction(Message $message, Subject $newSubject)
    {
        $forum = $newSubject->getCategory()->getForum();
        $this->checkAccess($forum);
        $this->manager->moveMessage($message, $newSubject);

        return new RedirectResponse(
            $this->generateUrl('claro_forum_subjects', array('category' => $newSubject->getCategory()->getId()))
        );
    }

    /**
     * @EXT\Route(
     *     "/subject/{subject}/move/{newCategory}",
     *     name="claro_subject_move",
     *     options={"expose"=true}
     * )
     * @EXT\Method("GET")
     *
     * @param Subject $subject
     * @param Category $newCategory
     */
    public function moveSubjectAction(Subject $subject, Category $newCategory)
    {
        $forum = $newCategory->getForum();
        $this->checkAccess($forum);
        $this->manager->moveSubject($subject, $newCategory);

        return new RedirectResponse(
            $this->generateUrl('claro_forum_categories', array('forum' => $forum->getId()))
        );
    }

    /**
     * @EXT\Route(
     *     "/stick/subject/{subject}",
     *     name="claro_subject_stick",
     *     options={"expose"=true}
     * )
     * @EXT\Method("GET")
     *
     * @param Subject $subject
     */
    public function stickSubjectAction(Subject $subject)
    {
        $forum = $subject->getCategory()->getForum();
        $this->checkAccess($forum);
        $this->manager->stickSubject($subject);

        return new RedirectResponse(
            $this->generateUrl('claro_forum_subjects', array('category' => $subject->getCategory()->getId()))
        );
    }

    /**
     * @EXT\Route(
     *     "/unstick/subject/{subject}",
     *     name="claro_subject_unstick",
     *     options={"expose"=true}
     * )
     * @EXT\Method("GET")
     *
     * @param Subject $subject
     */
    public function unstickSubjectAction(Subject $subject)
    {
        $forum = $subject->getCategory()->getForum();
        $this->checkAccess($forum);
        $this->manager->unstickSubject($subject);

        return new RedirectResponse(
            $this->generateUrl('claro_forum_subjects', array('category' => $subject->getCategory()->getId()))
        );
    }

    /**
     * @EXT\Route(
     *     "/close/subject/{subject}",
     *     name="claro_subject_close",
     *     options={"expose"=true}
     * )
     * @EXT\Method("GET")
     *
     * @param Subject $subject
     */
    public function closeSubjectAction(Subject $subject)
    {
        $forum = $subject->getCategory()->getForum();
        $this->checkAccess($forum);
        $this->manager->closeSubject($subject);

        return new RedirectResponse(
            $this->generateUrl('claro_forum_subjects', array('category' => $subject->getCategory()->getId()))
        );
    }

    /**
     * @EXT\Route(
     *     "/open/subject/{subject}",
     *     name="claro_subject_open",
     *     options={"expose"=true}
     * )
     * @EXT\Method("GET")
     *
     * @param Subject $subject
     */
    public function openSubjectAction(Subject $subject)
    {
        $forum = $subject->getCategory()->getForum();
        $this->checkAccess($forum);
        $this->manager->openSubject($subject);

        return new RedirectResponse(
            $this->generateUrl('claro_forum_subjects', array('category' => $subject->getCategory()->getId()))
        );
    }

    /**
     * @Route(
     *     "/reply/message/{message}",
     *     name="claro_forum_reply_message_form"
     * )
     * @ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     *
     * @Template("ClarolineForumBundle:Forum:replyMessageForm.html.twig")
     * @param Message $message
     */
     public function replyMessageAction(Message $message)
     {
        $subject = $message->getSubject();
        $forum = $subject->getCategory()->getForum();
        $reply = new Message();
        $form = $this->container->get('form.factory')->create(new MessageType, $reply);
        $form->handleRequest($this->get('request'));

        if ($form->isValid()) {
            $newMsg = $form->getData();
            $this->manager->createMessage($newMsg, $subject);

            return new RedirectResponse(
                $this->generateUrl('claro_forum_messages', array('subject' => $subject->getId()))
            );
        }

        return array(
            'subject' => $subject,
            'form' => $form->createView(),
            'message' => $message,
            '_resource' => $forum,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );

     }


    /**
     * @Route(
     *     "/quote/message/{message}",
     *     name="claro_forum_quote_message_form"
     * )
     * @ParamConverter("authenticatedUser", options={"authenticatedUser" = true})
     *
     * @Template("ClarolineForumBundle:Forum:quoteMessageForm.html.twig")
     * @param Message $message
     */
    public function quoteMessageAction(Message $message)
    {
        $subject = $message->getSubject();
        $forum = $subject->getCategory()->getForum();
        $reply = new Message();
        $reply->setContent($this->manager->getMessageQuoteHTML($message));
        $form = $this->container->get('form.factory')->create(new MessageType, $reply);
        $form->handleRequest($this->get('request'));

        if ($form->isValid()) {
            $newMsg = $form->getData();
            $this->manager->createMessage($newMsg, $subject);

            return new RedirectResponse(
                $this->generateUrl('claro_forum_messages', array('subject' => $subject->getId()))
            );
        }

        return array(
            'subject' => $subject,
            'form' => $form->createView(),
            'message' => $message,
            '_resource' => $forum,
            'workspace' => $forum->getResourceNode()->getWorkspace()
        );
    }

    /**
     * @Route(
     *     "/{forum}/notifications/activate",
     *     name="claro_forum_activate_global_notifications"
     * )
     *
     * @param Forum $forum
     */
    public function activateGlobalNotificationsAction(Forum $forum)
    {
        $collection = new ResourceCollection(array($forum->getResourceNode()));

        if (!$this->authorization->isGranted('MODERATE', $collection)) {
            throw new AccessDeniedException($collection->getErrorsForDisplay());
        }

        $this->manager->activateGlobalNotifications($forum);

        return new RedirectResponse(
            $this->generateUrl('claro_forum_categories', array('forum' => $forum->getId()))
        );
    }

    /**
     * @Route(
     *     "/{forum}/notifications/disable",
     *     name="claro_forum_disable_global_notifications"
     * )
     *
     * @param Forum $forum
     */
    public function disableGlobalNotificationsAction(Forum $forum)
    {
        $collection = new ResourceCollection(array($forum->getResourceNode()));

        if (!$this->authorization->isGranted('MODERATE', $collection)) {
            throw new AccessDeniedException($collection->getErrorsForDisplay());
        }

        $this->manager->disableGlobalNotifications($forum);

        return new RedirectResponse(
            $this->generateUrl('claro_forum_categories', array('forum' => $forum->getId()))
        );
    }

    private function isAnon()
    {
        return $this->tokenStorage->getToken()->getUser() === 'anon.';
    }
}
